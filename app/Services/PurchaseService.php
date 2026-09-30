<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Warehouse;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseService
{
    public function confirm(int $purchaseId, int $userId): void
    {
        DB::transaction(function () use ($purchaseId, $userId) {
            $user = User::whereKey($userId)->firstOrFail();
            if (!$user->isActive() || !$user->isOwner()) {
                throw new InvalidArgumentException('No tenés permiso para confirmar compras.');
            }

            $purchase = Purchase::where('company_id', $user->company_id)
                ->whereKey($purchaseId)
                ->lockForUpdate()
                ->firstOrFail();

            if (app()->environment('testing') && env('TEST_CONCURRENCY_SLEEP')) {
                sleep((int) env('TEST_CONCURRENCY_SLEEP'));
            }

            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new Exception("La compra ya no está en borrador. Estado actual: {$purchase->status->label()}");
            }

            if (!Warehouse::where('company_id', $purchase->company_id)->whereKey($purchase->warehouse_id)->exists()) {
                throw new InvalidArgumentException('El depósito de la compra no pertenece a la empresa activa.');
            }

            $purchase->load(['items.product.presentations', 'items.presentation']);
            $productService = app(ProductService::class);
            $stockService = app(StockService::class);

            foreach ($purchase->items as $item) {
                $product = Product::where('company_id', $purchase->company_id)->whereKey($item->product_id)->first();
                if (!$product) {
                    throw new InvalidArgumentException('La compra contiene un producto de otra empresa o inexistente.');
                }

                $presentation = null;
                if ($item->presentation_id) {
                    $presentation = $product->presentations()->whereKey($item->presentation_id)->first();
                    if (!$presentation) {
                        throw new InvalidArgumentException("La presentación de {$product->name} no corresponde al producto.");
                    }
                }

                if ($product->requires_lot && blank($item->lot_code)) {
                    throw new InvalidArgumentException("{$product->name} requiere un código de lote.");
                }
                if ($product->requires_expiration && !$item->expiration_date) {
                    throw new InvalidArgumentException("{$product->name} requiere fecha de vencimiento.");
                }

                $conversion = $productService->resolveToBaseUnit($product, $presentation, (float) $item->quantity);
                $item->update(['quantity_base' => $conversion['quantity_base']]);

                $lotData = $product->requires_lot ? [
                    'lot_code' => (string) $item->lot_code,
                    'expiration_date' => $item->expiration_date?->toDateString(),
                ] : [];

                $stockService->registerMovement([
                    'company_id' => $purchase->company_id,
                    'product_id' => $product->id,
                    'warehouse_id' => $purchase->warehouse_id,
                    'type' => MovementType::PurchaseIn,
                    'quantity_base' => $conversion['quantity_base'],
                    'presentation_id' => $conversion['presentation_id'],
                    'presentation_quantity' => $conversion['presentation_quantity'],
                    'reference_type' => Purchase::class,
                    'reference_id' => $purchase->id,
                    'user_id' => $user->id,
                    'channel' => Channel::Web,
                    'lot_data' => $lotData,
                ]);
            }

            $purchase->update(['status' => PurchaseStatus::Confirmed]);
        });
    }
}
