<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
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
    // Factory unit constants — confirmed business rules
    public const HAMBURGUESAS_PER_BANDEJA = 24;
    public const BANDEJAS_PER_CARRO = 12;
    public const HAMBURGUESAS_PER_CARRO = self::HAMBURGUESAS_PER_BANDEJA * self::BANDEJAS_PER_CARRO; // 288

    public function __construct(
        private StockService $stockService,
        private ProductionCalculatorService $calculatorService,
    ) {}

    /**
     * Convert carros/bandejas to hamburguesa units.
     * Examples:
     *   toHamburguesas(3, 0)   → 864
     *   toHamburguesas(3, 2)   → 912
     *   toHamburguesas(3.5, 0) → 1008 (3 carros y medio = 3×288 + 6×24)
     */
    public static function toHamburguesas(float $carros = 0, float $bandejas = 0): float
    {
        return ($carros * self::HAMBURGUESAS_PER_CARRO) + ($bandejas * self::HAMBURGUESAS_PER_BANDEJA);
    }

    /**
     * Register a completed production run.
     *
     * Stock NEGATIVE is allowed by design (user decision). If any ingredient
     * falls below zero, a warning is returned but the operation is NOT blocked.
     *
     * @param User    $user
     * @param Product $finishedProduct
     * @param float   $quantity         Actual number of units produced
     * @param array   $actualConsumptions  Optional. [product_id => qty_base, ...].
     *                If empty, theoretical quantities from the recipe are used.
     * @param Channel $channel
     * @return array  ['order' => ProductionOrder, 'warnings' => string[]]
     */
    public function registerProduction(
        User $user,
        Product $finishedProduct,
        float $quantity,
        array $actualConsumptions = [],
        Channel $channel = Channel::Web,
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("La cantidad producida debe ser mayor a 0.");
        }

        $warnings = [];

        return DB::transaction(function () use ($user, $finishedProduct, $quantity, $actualConsumptions, $channel, &$warnings) {
            $companyId = $user->company_id;

            $warehouse = Warehouse::where('company_id', $companyId)->firstOrFail();

            // 1. Load recipe and calculate theoretical requirements
            $recipe = $finishedProduct->recipe()->with('items.product')->first();
            if (!$recipe) {
                throw new Exception("El producto '{$finishedProduct->name}' no tiene una receta configurada.");
            }

            $multiplier = $quantity / $recipe->yield_quantity;

            // 2. Build consumption map: use actual if provided, otherwise theoretical
            // NOTE: When user informs real consumptions, we use them verbatim
            // and do NOT overwrite with theoretical values.
            $consumptionMap = []; // product_id => qty_base to consume

            if (!empty($actualConsumptions)) {
                foreach ($actualConsumptions as $productId => $qtyBase) {
                    $consumptionMap[(int) $productId] = (float) $qtyBase;
                }
            } else {
                foreach ($recipe->items as $item) {
                    $consumptionMap[$item->product_id] = $item->quantity_base * $multiplier;
                }
            }

            // 3. Create production order record
            $order = ProductionOrder::create([
                'company_id' => $companyId,
                'product_id' => $finishedProduct->id,
                'warehouse_id' => $warehouse->id,
                'target_quantity' => $quantity,
                'status' => 'completed',
                'user_id' => $user->id,
            ]);

            // 4. Record order items (theoretical required vs actually consumed)
            foreach ($recipe->items as $item) {
                $required = $item->quantity_base * $multiplier;
                $consumed = $consumptionMap[$item->product_id] ?? $required;
                ProductionOrderItem::create([
                    'production_order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'required_quantity' => $required,
                    'consumed_quantity' => $consumed,
                ]);
            }

            // 5. Deduct ingredient stock — ALLOW NEGATIVES
            // Each ingredient's consumption is deducted individually.
            foreach ($consumptionMap as $productId => $qtyBase) {
                if ($qtyBase <= 0) {
                    continue;
                }

                $ingredient = Product::find($productId);
                if (!$ingredient) {
                    continue;
                }

                // Try normal deduction first
                try {
                    $this->stockService->registerMovement([
                        'company_id' => $companyId,
                        'product_id' => $productId,
                        'warehouse_id' => $warehouse->id,
                        'type' => MovementType::ProductionConsumption,
                        'quantity_base' => $qtyBase,
                        'user_id' => $user->id,
                        'channel' => $channel,
                        'reason' => 'Producción: ' . $finishedProduct->name . ' × ' . $quantity,
                        'reference_type' => 'production_orders',
                        'reference_id' => $order->id,
                    ]);
                } catch (Exception $e) {
                    // StockService threw because stock would go negative.
                    // We ALLOW this — force-deduct and record a warning.
                    $this->forceDeductStock(
                        $companyId, $productId, $warehouse->id,
                        $qtyBase, $user->id, $channel,
                        $order->id, $finishedProduct->name, $quantity,
                        $warnings, $ingredient->name
                    );
                }
            }

            // 6. Record production output and add finished product stock
            ProductionOutput::create([
                'production_order_id' => $order->id,
                'quantity_produced' => $quantity,
            ]);

            $this->stockService->registerMovement([
                'company_id' => $companyId,
                'product_id' => $finishedProduct->id,
                'warehouse_id' => $warehouse->id,
                'type' => MovementType::ProductionOutput,
                'quantity_base' => $quantity,
                'user_id' => $user->id,
                'channel' => $channel,
                'reason' => 'Producción registrada',
                'reference_type' => 'production_orders',
                'reference_id' => $order->id,
            ]);

            return ['order' => $order, 'warnings' => $warnings];
        });
    }

    /**
     * Force a stock deduction even when it results in negative stock.
     * Bypasses StockService's negativity guard by writing directly.
     * Adds a warning to the caller's $warnings array.
     */
    private function forceDeductStock(
        int $companyId, int $productId, int $warehouseId,
        float $qtyBase, int $userId, Channel $channel,
        int $orderId, string $finishedName, float $finishedQty,
        array &$warnings, string $ingredientName
    ): void {
        $stock = Stock::where([
            'company_id' => $companyId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'lot_id' => null,
        ])->lockForUpdate()->first();

        $currentQty = $stock ? (float) $stock->quantity : 0.0;
        $newQty = $currentQty - $qtyBase;

        if ($stock) {
            $stock->update(['quantity' => $newQty]);
        } else {
            Stock::create([
                'company_id' => $companyId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'lot_id' => null,
                'quantity' => $newQty,
            ]);
        }

        // Insert the movement record with explicit negative sign
        StockMovement::create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'type' => MovementType::ProductionConsumption,
            'quantity_base' => -abs($qtyBase),
            'user_id' => $userId,
            'channel' => $channel,
            'reason' => 'Producción (stock negativo permitido): ' . $finishedName . ' × ' . $finishedQty,
            'reference_type' => 'production_orders',
            'reference_id' => $orderId,
            'created_at' => now(),
        ]);

        $warnings[] = sprintf(
            '⚠️ %s quedó en %s unidades. Revisá el inventario.',
            $ingredientName,
            number_format($newQty, 0)
        );
    }
}
