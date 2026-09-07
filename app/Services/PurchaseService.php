<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use Exception;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function confirm(int $purchaseId, int $userId): void
    {
        DB::transaction(function () use ($purchaseId, $userId) {
            // 1. Lock explícito a la compra ANTES de validar el status
            $purchase = Purchase::where('id', $purchaseId)->lockForUpdate()->firstOrFail();

            // Support test concurrency logic (similar to StockService)
            if (app()->environment('testing') && env('TEST_CONCURRENCY_SLEEP')) {
                sleep((int) env('TEST_CONCURRENCY_SLEEP'));
            }

            // 2. Validación de status post-lock
            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new Exception("La compra ya no está en borrador. Estado actual: {$purchase->status->label()}");
            }

            $productService = app(ProductService::class);
            $stockService = app(StockService::class);

            // 3. Procesar ítems y registrar stock
            foreach ($purchase->items as $item) {
                $conversion = $productService->resolveToBaseUnit(
                    $item->product,
                    $item->presentation,
                    (float) $item->quantity
                );

                // Guardamos la quantity_base histórica en el ítem de compra
                $item->update(['quantity_base' => $conversion['quantity_base']]);

                $lotData = [];
                if ($item->product->requires_lot) {
                    $lotData = [
                        'lot_code' => $item->lot_code,
                        'expiration_date' => $item->expiration_date ? $item->expiration_date->toDateString() : null,
                    ];
                }

                $stockService->registerMovement([
                    'company_id' => $purchase->company_id,
                    'product_id' => $item->product_id,
                    'warehouse_id' => $purchase->warehouse_id,
                    'type' => MovementType::PurchaseIn,
                    'quantity_base' => $conversion['quantity_base'],
                    'presentation_id' => $conversion['presentation_id'],
                    'presentation_quantity' => $conversion['presentation_quantity'],
                    'reference_type' => Purchase::class,
                    'reference_id' => $purchase->id,
                    'user_id' => $userId,
                    'channel' => Channel::Web,
                    'lot_data' => $lotData,
                ]);
            }

            // 4. Actualizar estado
            $purchase->update(['status' => PurchaseStatus::Confirmed]);
        });
    }
}
