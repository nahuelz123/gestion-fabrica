<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionOutput;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductionOrderService
{
    public const HAMBURGUESAS_PER_BANDEJA = 24;
    public const BANDEJAS_PER_CARRO = 12;
    public const HAMBURGUESAS_PER_CARRO = self::HAMBURGUESAS_PER_BANDEJA * self::BANDEJAS_PER_CARRO;

    public function __construct(
        private StockService $stockService,
        private ProductionCalculatorService $calculatorService,
    ) {}

    public static function toHamburguesas(float $carros = 0, float $bandejas = 0): float
    {
        return ($carros * self::HAMBURGUESAS_PER_CARRO) + ($bandejas * self::HAMBURGUESAS_PER_BANDEJA);
    }

    public function registerProduction(
        User $user,
        Product $finishedProduct,
        float $quantity,
        array $actualConsumptions = [],
        Channel $channel = Channel::Web,
    ): array {
        if (!is_finite($quantity) || $quantity <= 0) {
            throw new InvalidArgumentException('La cantidad producida debe ser mayor a 0.');
        }
        if (!$user->isActive() || !$user->canManageProduction()) {
            throw new InvalidArgumentException('No tenés permiso para registrar producción.');
        }

        return DB::transaction(function () use ($user, $finishedProduct, $quantity, $actualConsumptions, $channel) {
            $companyId = $user->company_id;
            $user = User::where('company_id', $companyId)->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $finishedProduct = Product::where('company_id', $companyId)->whereKey($finishedProduct->id)->lockForUpdate()->firstOrFail();
            if ($finishedProduct->type !== ProductType::FinishedProduct) {
                throw new InvalidArgumentException('El producto seleccionado no es un producto final.');
            }
            if ($finishedProduct->requires_expiration && !$finishedProduct->shelf_life_days) {
                throw new InvalidArgumentException('El producto final requiere vencimiento pero no tiene días de vida útil configurados.');
            }

            $warehouse = Warehouse::where('company_id', $companyId)->orderBy('id')->firstOrFail();
            $recipe = $finishedProduct->recipe()->with('items.product')->first();
            if (!$recipe || (float) $recipe->yield_quantity <= 0 || $recipe->items->isEmpty()) {
                throw new Exception("El producto '{$finishedProduct->name}' no tiene una receta válida configurada.");
            }

            foreach ($recipe->items as $item) {
                if (!$item->product || $item->product->company_id !== $companyId) {
                    throw new InvalidArgumentException('La receta contiene un insumo de otra empresa o inexistente.');
                }
            }

            $multiplier = $quantity / (float) $recipe->yield_quantity;

            // Partimos siempre de los consumos teóricos de la receta. Si el encargado
            // informa consumos reales, sólo reemplazamos los ingredientes indicados.
            // Así una corrección parcial no hace que otros insumos queden en consumo 0.
            $consumptionMap = [];
            $recipeProductIds = [];
            foreach ($recipe->items as $item) {
                $recipeProductIds[(int) $item->product_id] = true;
                $consumptionMap[(int) $item->product_id] = (float) $item->quantity_base * $multiplier;
            }

            foreach ($actualConsumptions as $productId => $qtyBase) {
                $productId = (int) $productId;
                $qtyBase = (float) $qtyBase;
                if ($productId <= 0 || !is_finite($qtyBase) || $qtyBase < 0) {
                    throw new InvalidArgumentException('Los consumos reales informados no son válidos.');
                }
                if (!isset($recipeProductIds[$productId])) {
                    throw new InvalidArgumentException('Sólo se pueden informar consumos reales de ingredientes de la receta.');
                }
                $consumptionMap[$productId] = $qtyBase;
            }

            $order = ProductionOrder::create([
                'company_id'=>$companyId,'product_id'=>$finishedProduct->id,'warehouse_id'=>$warehouse->id,
                'target_quantity'=>$quantity,'status'=>'completed','user_id'=>$user->id,
            ]);

            foreach ($recipe->items as $item) {
                $required = (float) $item->quantity_base * $multiplier;
                ProductionOrderItem::create([
                    'production_order_id'=>$order->id,'product_id'=>$item->product_id,'required_quantity'=>$required,
                    'consumed_quantity'=>$consumptionMap[$item->product_id] ?? 0,
                ]);
            }

            $warnings = [];
            foreach ($consumptionMap as $productId => $qtyBase) {
                if ($qtyBase <= 0) continue;
                $ingredient = Product::where('company_id', $companyId)->whereKey($productId)->firstOrFail();
                if ($ingredient->requires_lot) {
                    $this->deductLotControlled($ingredient, $warehouse->id, $qtyBase, $user->id, $channel, $order, $finishedProduct, $quantity);
                    continue;
                }

                try {
                    $this->stockService->registerMovement([
                        'company_id'=>$companyId,'product_id'=>$ingredient->id,'warehouse_id'=>$warehouse->id,
                        'type'=>MovementType::ProductionConsumption,'quantity_base'=>$qtyBase,'user_id'=>$user->id,'channel'=>$channel,
                        'reason'=>'Producción: '.$finishedProduct->name.' × '.$quantity,
                        'reference_type'=>ProductionOrder::class,'reference_id'=>$order->id,
                    ]);
                } catch (Exception) {
                    $this->forceDeductStock($companyId,$ingredient->id,$warehouse->id,$qtyBase,$user->id,$channel,$order->id,$finishedProduct->name,$quantity,$warnings,$ingredient->name);
                }
            }

            $lotData = [];
            $lotCode = null;
            $expirationDate = null;
            if ($finishedProduct->requires_lot) {
                $lotCode = 'PROD-'.now()->format('Ymd').'-'.$order->id;
                $expirationDate = $finishedProduct->requires_expiration
                    ? now()->addDays((int) $finishedProduct->shelf_life_days)->toDateString()
                    : null;
                $lotData = ['lot_code'=>$lotCode,'production_date'=>now()->toDateString(),'expiration_date'=>$expirationDate];
            }

            ProductionOutput::create([
                'production_order_id'=>$order->id,'lot_code'=>$lotCode,'expiration_date'=>$expirationDate,'quantity_produced'=>$quantity,
            ]);

            $this->stockService->registerMovement([
                'company_id'=>$companyId,'product_id'=>$finishedProduct->id,'warehouse_id'=>$warehouse->id,
                'type'=>MovementType::ProductionOutput,'quantity_base'=>$quantity,'user_id'=>$user->id,'channel'=>$channel,
                'reason'=>'Producción registrada','reference_type'=>ProductionOrder::class,'reference_id'=>$order->id,'lot_data'=>$lotData,
            ]);

            return ['order'=>$order,'warnings'=>$warnings];
        });
    }

    private function deductLotControlled(
        Product $ingredient, int $warehouseId, float $quantity, int $userId, Channel $channel,
        ProductionOrder $order, Product $finishedProduct, float $finishedQty
    ): void {
        $pending = $quantity;
        $stocks = Stock::query()
            ->where('stock.company_id', $ingredient->company_id)
            ->where('stock.product_id', $ingredient->id)
            ->where('stock.warehouse_id', $warehouseId)
            ->whereNotNull('stock.lot_id')
            ->where('stock.quantity', '>', 0)
            ->join('stock_lots', 'stock.lot_id', '=', 'stock_lots.id')
            ->orderByRaw('stock_lots.expiration_date IS NULL ASC')
            ->orderBy('stock_lots.expiration_date')
            ->orderBy('stock_lots.entry_date')
            ->select('stock.*')
            ->lockForUpdate()
            ->get();

        foreach ($stocks as $stock) {
            if ($pending <= 0.000001) break;
            $take = min($pending, (float) $stock->quantity);
            if ($take <= 0) continue;
            $this->stockService->registerMovement([
                'company_id'=>$ingredient->company_id,'product_id'=>$ingredient->id,'warehouse_id'=>$warehouseId,
                'lot_id'=>$stock->lot_id,'type'=>MovementType::ProductionConsumption,'quantity_base'=>$take,
                'user_id'=>$userId,'channel'=>$channel,'reason'=>'Producción: '.$finishedProduct->name.' × '.$finishedQty,
                'reference_type'=>ProductionOrder::class,'reference_id'=>$order->id,
            ]);
            $pending -= $take;
        }

        if ($pending > 0.000001) {
            throw new Exception("No alcanza el stock loteado de {$ingredient->name}. Faltan {$pending} unidades y no se permite crear stock negativo sin identificar lote.");
        }
    }

    private function forceDeductStock(
        int $companyId, int $productId, int $warehouseId, float $qtyBase, int $userId, Channel $channel,
        int $orderId, string $finishedName, float $finishedQty, array &$warnings, string $ingredientName
    ): void {
        $product = Product::where('company_id',$companyId)->whereKey($productId)->firstOrFail();
        if ($product->requires_lot) throw new InvalidArgumentException('No se puede forzar stock negativo en un producto controlado por lote.');
        if (!Warehouse::where('company_id',$companyId)->whereKey($warehouseId)->exists()) throw new InvalidArgumentException('Depósito inválido.');
        if (!User::where('company_id',$companyId)->whereKey($userId)->exists()) throw new InvalidArgumentException('Usuario inválido.');

        $stock = Stock::where('company_id',$companyId)->where('product_id',$productId)->where('warehouse_id',$warehouseId)->whereNull('lot_id')->lockForUpdate()->first();
        $currentQty = $stock ? (float)$stock->quantity : 0.0;
        $newQty = $currentQty - $qtyBase;
        if ($stock) $stock->update(['quantity'=>$newQty]);
        else Stock::create(['company_id'=>$companyId,'product_id'=>$productId,'warehouse_id'=>$warehouseId,'lot_id'=>null,'quantity'=>$newQty]);

        StockMovement::create([
            'company_id'=>$companyId,'product_id'=>$productId,'warehouse_id'=>$warehouseId,'type'=>MovementType::ProductionConsumption,
            'quantity_base'=>-abs($qtyBase),'user_id'=>$userId,'channel'=>$channel,
            'reason'=>'Producción (stock negativo permitido): '.$finishedName.' × '.$finishedQty,
            'reference_type'=>ProductionOrder::class,'reference_id'=>$orderId,'created_at'=>now(),
        ]);
        $warnings[] = sprintf('⚠️ %s quedó en %s unidades. Revisá el inventario.', $ingredientName, number_format($newQty, 0));
    }
}
