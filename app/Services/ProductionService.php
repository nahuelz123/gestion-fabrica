<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Stock;
use Exception;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public function __construct(
        private ProductionCalculatorService $calculator,
        private StockService $stockService
    ) {}

    public function executeProduction(
        int $productId,
        int $warehouseId,
        float $targetQuantity,
        array $outputLotData,
        int $userId
    ): ProductionOrder {
        return DB::transaction(function () use ($productId, $warehouseId, $targetQuantity, $outputLotData, $userId) {
            $product = Product::findOrFail($productId);
            
            // 1. Verificar simulación (es una doble validación de seguridad)
            $calculation = $this->calculator->calculateRequirements($product, $targetQuantity);
            
            if (!$calculation['can_produce']) {
                throw new Exception("No hay stock suficiente para producir la cantidad solicitada.");
            }

            // 2. Crear Orden
            $order = ProductionOrder::create([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'target_quantity' => $targetQuantity,
                'status' => 'completed',
                'user_id' => $userId,
            ]);

            // 3. Consumir Insumos respetando FEFO
            foreach ($calculation['items'] as $item) {
                $ingredient = $item['ingredient'];
                $pendingToConsume = $item['required'];
                $totalConsumed = 0;

                if ($ingredient->requires_lot) {
                    // INSUMO CON LOTE: Estrategia FEFO
                    // Obtenemos los lotes candidatos ordenados por vencimiento (solo el ID para iterar)
                    $candidateLots = Stock::where('stock.company_id', $product->company_id)
                        ->where('stock.product_id', $ingredient->id)
                        ->where('stock.quantity', '>', 0)
                        ->join('stock_lots', 'stock.lot_id', '=', 'stock_lots.id')
                        ->orderBy('stock_lots.expiration_date', 'asc')
                        ->pluck('stock.lot_id');

                    foreach ($candidateLots as $lotId) {
                        if ($pendingToConsume <= 0) break;

                        // Leemos el stock actual EXACTO de este lote justo antes de consumirlo
                        $currentStock = Stock::where('product_id', $ingredient->id)
                            ->where('lot_id', $lotId)
                            ->value('quantity') ?? 0;

                        if ($currentStock > 0) {
                            $consumeAmount = min($pendingToConsume, $currentStock);
                            
                            $this->stockService->registerMovement([
                                'company_id' => $product->company_id,
                                'product_id' => $ingredient->id,
                                'warehouse_id' => $warehouseId,
                                'type' => MovementType::ProductionConsumption,
                                'quantity_base' => -$consumeAmount,
                                'reference_type' => ProductionOrder::class,
                                'reference_id' => $order->id,
                                'user_id' => $userId,
                                'channel' => Channel::Web,
                                'lot_id' => $lotId,
                            ]);

                            $pendingToConsume -= $consumeAmount;
                            $totalConsumed += $consumeAmount;
                        }
                    }
                } else {
                    // INSUMO SIN LOTE: Consumo directo
                    $currentStock = Stock::where('product_id', $ingredient->id)
                        ->whereNull('lot_id')
                        ->value('quantity') ?? 0;

                    if ($currentStock >= $pendingToConsume) {
                        $this->stockService->registerMovement([
                            'company_id' => $product->company_id,
                            'product_id' => $ingredient->id,
                            'warehouse_id' => $warehouseId,
                            'type' => MovementType::ProductionConsumption,
                            'quantity_base' => -$pendingToConsume,
                            'reference_type' => ProductionOrder::class,
                            'reference_id' => $order->id,
                            'user_id' => $userId,
                            'channel' => Channel::Web,
                            'lot_id' => null,
                        ]);
                        $totalConsumed += $pendingToConsume;
                        $pendingToConsume = 0;
                    }
                }

                // Si recorrimos los candidatos y no alcanzó (por concurrencia u otro motivo)
                if (round($pendingToConsume, 4) > 0) {
                    throw new Exception("No alcanza el stock de {$ingredient->name} al momento de confirmar, faltan {$pendingToConsume}.");
                }

                // Guardar el snapshot
                $order->items()->create([
                    'product_id' => $ingredient->id,
                    'required_quantity' => $item['required'],
                    'consumed_quantity' => $totalConsumed,
                ]);
            }

            // 4. Generar el Producto Terminado
            $lotId = null;
            if ($product->requires_lot) {
                if (empty($outputLotData['lot_code'])) {
                    throw new Exception("El producto final requiere un código de lote.");
                }
            }

            $outputMovement = $this->stockService->registerMovement([
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'type' => MovementType::ProductionOutput,
                'quantity_base' => $targetQuantity,
                'reference_type' => ProductionOrder::class,
                'reference_id' => $order->id,
                'user_id' => $userId,
                'channel' => Channel::Web,
                'lot_data' => $outputLotData,
            ]);

            // Guardar registro de la salida real
            $order->output()->create([
                'lot_code' => $outputLotData['lot_code'] ?? null,
                'expiration_date' => $outputLotData['expiration_date'] ?? null,
                'quantity_produced' => $targetQuantity,
            ]);

            return $order;
        });
    }
}
