<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductionService
{
    public function __construct(private ProductionCalculatorService $calculator, private StockService $stockService) {}

    public function executeProduction(int $productId, int $warehouseId, float $targetQuantity, array $outputLotData, int $userId): ProductionOrder
    {
        if (!is_finite($targetQuantity) || $targetQuantity <= 0) throw new InvalidArgumentException('La cantidad a producir debe ser mayor a 0.');
        return DB::transaction(function () use ($productId,$warehouseId,$targetQuantity,$outputLotData,$userId) {
            $user = User::whereKey($userId)->firstOrFail();
            if (!$user->isActive() || !$user->isOwner()) throw new InvalidArgumentException('No tenés permiso para registrar producción.');
            $companyId = $user->company_id;
            $product = Product::where('company_id',$companyId)->whereKey($productId)->lockForUpdate()->firstOrFail();
            $warehouse = Warehouse::where('company_id',$companyId)->whereKey($warehouseId)->firstOrFail();
            $calculation = $this->calculator->calculateRequirements($product,$targetQuantity);
            if (!$calculation['can_produce']) throw new Exception('No hay stock suficiente para producir la cantidad solicitada.');

            $order = ProductionOrder::create(['company_id'=>$companyId,'product_id'=>$product->id,'warehouse_id'=>$warehouse->id,'target_quantity'=>$targetQuantity,'status'=>'completed','user_id'=>$user->id]);

            foreach ($calculation['items'] as $item) {
                $ingredient = Product::where('company_id',$companyId)->whereKey($item['ingredient']->id)->firstOrFail();
                $pending = (float)$item['required']; $totalConsumed = 0.0;
                if ($ingredient->requires_lot) {
                    $candidateStocks = Stock::query()->where('stock.company_id',$companyId)->where('stock.product_id',$ingredient->id)
                        ->where('stock.warehouse_id',$warehouse->id)->where('stock.quantity','>',0)->whereNotNull('stock.lot_id')
                        ->join('stock_lots','stock.lot_id','=','stock_lots.id')
                        ->orderByRaw('stock_lots.expiration_date IS NULL ASC')->orderBy('stock_lots.expiration_date')->orderBy('stock_lots.entry_date')
                        ->select('stock.*')->lockForUpdate()->get();
                    foreach ($candidateStocks as $stock) {
                        if ($pending <= 0.000001) break;
                        $consume = min($pending,(float)$stock->quantity);
                        if ($consume <= 0) continue;
                        $this->stockService->registerMovement([
                            'company_id'=>$companyId,'product_id'=>$ingredient->id,'warehouse_id'=>$warehouse->id,'lot_id'=>$stock->lot_id,
                            'type'=>MovementType::ProductionConsumption,'quantity_base'=>$consume,'reference_type'=>ProductionOrder::class,'reference_id'=>$order->id,
                            'user_id'=>$user->id,'channel'=>Channel::Web,
                        ]);
                        $pending -= $consume; $totalConsumed += $consume;
                    }
                } else {
                    $currentStock = (float)(Stock::where('company_id',$companyId)->where('product_id',$ingredient->id)->where('warehouse_id',$warehouse->id)->whereNull('lot_id')->lockForUpdate()->value('quantity') ?? 0);
                    if ($currentStock >= $pending) {
                        $this->stockService->registerMovement([
                            'company_id'=>$companyId,'product_id'=>$ingredient->id,'warehouse_id'=>$warehouse->id,'type'=>MovementType::ProductionConsumption,
                            'quantity_base'=>$pending,'reference_type'=>ProductionOrder::class,'reference_id'=>$order->id,'user_id'=>$user->id,'channel'=>Channel::Web,
                        ]);
                        $totalConsumed += $pending; $pending = 0;
                    }
                }
                if ($pending > 0.0001) throw new Exception("No alcanza el stock de {$ingredient->name} al momento de confirmar, faltan {$pending}.");
                $order->items()->create(['product_id'=>$ingredient->id,'required_quantity'=>$item['required'],'consumed_quantity'=>$totalConsumed]);
            }

            if ($product->requires_lot && empty($outputLotData['lot_code'])) throw new Exception('El producto final requiere un código de lote.');
            if ($product->requires_expiration && empty($outputLotData['expiration_date'])) {
                if ($product->shelf_life_days) $outputLotData['expiration_date'] = now()->addDays((int)$product->shelf_life_days)->toDateString();
                else throw new Exception('El producto final requiere fecha de vencimiento.');
            }
            if ($product->requires_lot) $outputLotData['production_date'] = $outputLotData['production_date'] ?? now()->toDateString();

            $this->stockService->registerMovement([
                'company_id'=>$companyId,'product_id'=>$product->id,'warehouse_id'=>$warehouse->id,'type'=>MovementType::ProductionOutput,
                'quantity_base'=>$targetQuantity,'reference_type'=>ProductionOrder::class,'reference_id'=>$order->id,'user_id'=>$user->id,'channel'=>Channel::Web,
                'lot_data'=>$outputLotData,
            ]);
            $order->output()->create(['lot_code'=>$outputLotData['lot_code'] ?? null,'expiration_date'=>$outputLotData['expiration_date'] ?? null,'quantity_produced'=>$targetQuantity]);
            return $order;
        });
    }
}
