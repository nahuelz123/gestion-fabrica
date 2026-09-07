<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockLot;
use App\Models\StockMovement;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    /**
     * @param array $data Expected keys:
     * - int $company_id
     * - int $product_id
     * - int $warehouse_id
     * - MovementType $type
     * - float $quantity_base (Absolute value; sign applied by type)
     * - int|null $presentation_id
     * - float|null $presentation_quantity
     * - int $user_id
     * - Channel $channel
     * - string|null $reason
     * - array|null $lot_data (required if product requires lot and lot_id is null)
     *   - string $lot_code
     *   - string|null $production_date
     *   - string|null $expiration_date
     * - int|null $lot_id
     */
    public function registerMovement(array $data): StockMovement
    {
        return DB::transaction(function () use ($data) {
            $productId = $data['product_id'];

            // PASO 1 - Lock en el producto (siempre existe, serializa todo para evitar race conditions al crear stock_lots o stock)
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            // Garantía de seguridad: El sleep sólo puede ejecutarse si el entorno es ESTRICTAMENTE 'testing'
            // y existe la variable de entorno. Imposible de gatillar en producción o dev.
            if (app()->environment('testing') && env('TEST_CONCURRENCY_SLEEP')) {
                sleep((int) env('TEST_CONCURRENCY_SLEEP'));
            }

            $lotId = $data['lot_id'] ?? null;

            // PASO 2 - Si requires_lot, buscar o crear StockLot
            if ($product->requires_lot) {
                if (!$lotId) {
                    if (empty($data['lot_data']['lot_code'])) {
                        throw new InvalidArgumentException("El producto requiere un código de lote.");
                    }

                    $lotCode = $data['lot_data']['lot_code'];
                    $lot = StockLot::where('product_id', $productId)
                        ->where('lot_code', $lotCode)
                        ->first();

                    if (!$lot) {
                        $lot = StockLot::create([
                            'company_id' => $data['company_id'],
                            'product_id' => $productId,
                            'lot_code' => $lotCode,
                            'production_date' => $data['lot_data']['production_date'] ?? null,
                            'entry_date' => now()->toDateString(),
                            'expiration_date' => $data['lot_data']['expiration_date'] ?? null,
                            'initial_quantity' => !$data['type']->isOutbound() ? abs($data['quantity_base']) : 0,
                        ]);
                    }

                    $lotId = $lot->id;
                }
            } else {
                $lotId = null; // Ensure lot is null if not required
            }

            // Aplicar signo a la cantidad base
            $quantityBase = abs((float) $data['quantity_base']);
            if ($data['type']->isOutbound()) {
                $quantityBase = -$quantityBase;
            }

            // PASO 3 - INSERT stock_movement
            $movement = StockMovement::create([
                'company_id' => $data['company_id'],
                'product_id' => $productId,
                'warehouse_id' => $data['warehouse_id'],
                'lot_id' => $lotId,
                'type' => $data['type'],
                'quantity_base' => $quantityBase,
                'presentation_id' => $data['presentation_id'] ?? null,
                'presentation_quantity' => $data['presentation_quantity'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'user_id' => $data['user_id'],
                'channel' => $data['channel'] ?? Channel::Web,
                'reason' => $data['reason'] ?? null,
                'created_at' => now(),
            ]);

            // PASO 4 - Buscar fila de stock(product, warehouse, lot) o crearla
            $stock = Stock::where([
                'product_id' => $productId,
                'warehouse_id' => $data['warehouse_id'],
                'lot_id' => $lotId,
            ])->lockForUpdate()->first();

            if (!$stock) {
                $stock = Stock::create([
                    'company_id' => $data['company_id'],
                    'product_id' => $productId,
                    'warehouse_id' => $data['warehouse_id'],
                    'lot_id' => $lotId,
                    'quantity' => 0,
                ]);
            }

            // PASO 5 - Validar stock >= 0 en salidas y actualizar
            $newQuantity = $stock->quantity + $quantityBase;

            if ($newQuantity < 0) {
                throw new Exception("Stock insuficiente para el producto '{$product->name}'. Disponible: {$stock->quantity}. Requerido: " . abs($quantityBase));
            }

            $stock->update(['quantity' => $newQuantity]);

            return $movement;
        });
    }
}
