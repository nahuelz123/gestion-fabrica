<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Enums\Channel;
use App\Enums\MovementType;
use Illuminate\Support\Facades\DB;
use Exception;

class BotActionExecutor
{
    private ProductService $productService;
    private StockService $stockService;
    private ProductionCalculatorService $calculatorService;
    private ProductionOrderService $productionOrderService;

    public function __construct(
        ProductService $productService,
        StockService $stockService,
        ProductionCalculatorService $calculatorService,
        ProductionOrderService $productionOrderService,
    ) {
        $this->productService = $productService;
        $this->stockService = $stockService;
        $this->calculatorService = $calculatorService;
        $this->productionOrderService = $productionOrderService;
    }

    public function execute(User $user, string $chatId, array $action, array $context): array
    {
        $actionName = $action['name'] ?? null;
        $args = $action['arguments'] ?? [];
        $companyId = $user->company_id;

        try {
            return DB::transaction(function () use ($actionName, $args, $companyId, $user) {
                return match ($actionName) {
                    'get_stock' => $this->executeGetStock($companyId, $args),
                    'get_low_stock' => $this->executeGetLowStock($companyId),
                    'create_product' => $this->executeCreateProduct($companyId, $args),
                    'register_stock' => $this->executeRegisterStock($companyId, $user->id, $args),
                    'adjust_stock' => $this->executeAdjustStock($companyId, $user->id, $args),
                    'set_stock' => $this->executeSetStock($companyId, $user->id, $args),
                    'add_stock' => $this->executeAddStock($companyId, $user->id, $args),
                    'remove_stock' => $this->executeRemoveStock($companyId, $user->id, $args),
                    'register_production' => $this->executeRegisterProduction($user, $args),
                    'get_recipe' => $this->executeGetRecipe($companyId, $args),
                    'calculate_production' => $this->executeCalculateProduction($companyId, $args),
                    'check_production' => $this->executeCheckProduction($companyId, $args),
                    'get_max_production' => $this->executeGetMaxProduction($companyId, $args),
                    'get_max_production' => $this->executeGetMaxProduction($companyId, $args),
                    'get_missing_inputs' => $this->executeGetMissingInputs($companyId, $args),
                    default => [
                        'success' => false,
                        'message' => 'Todavía no puedo realizar esa operación.'
                    ],
                };
            });
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'No pude completar la operación debido a un problema interno.'
            ];
        }
    }

    private function findProduct(int $companyId, string $name): array
    {
        $products = Product::where('company_id', $companyId)
            ->where('name', 'like', "%{$name}%")
            ->get();

        if ($products->count() === 0) {
            return ['success' => false, 'message' => "No encontré ningún producto llamado '{$name}' en tu empresa."];
        }

        if ($products->count() > 1) {
            return ['success' => false, 'message' => "Encontré varios productos que coinciden con '{$name}'. ¿Cuál querés?"];
        }

        return ['success' => true, 'product' => $products->first()];
    }

    private function executeGetStock(int $companyId, array $args): array
    {
        $name = $args['product_name'] ?? null;
        if (!$name) {
            return ['success' => false, 'message' => '¿De qué producto querés consultar el stock?'];
        }

        $result = $this->findProduct($companyId, $name);
        if (!$result['success']) {
            return $result;
        }

        $product = $result['product'];
        $qty = (float) Stock::where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->sum('quantity');

        $baseUnit = $product->baseUnit->abbreviation ?? 'u';
        $message = "Tenemos " . $this->formatNumber($qty) . " {$baseUnit} de {$product->name}";

        $presentation = $product->presentations()
            ->where('is_purchase_default', true)
            ->first();

        if ($presentation && (float) $presentation->conversion_factor > 1) {
            $factor = (float) $presentation->conversion_factor;
            $physical = $qty / $factor;
            $message .= " (" . $this->formatNumber($physical) . " {$presentation->name})";
        }

        return [
            'success' => true,
            'message' => $message . '.'
        ];
    }

    private function executeGetLowStock(int $companyId): array
    {
        // El proyecto actualmente no tiene regla clara configurada en BD para low stock.
        return [
            'success' => false,
            'message' => 'La alerta de stock mínimo todavía no está configurada en el sistema.'
        ];
    }

    private function executeCreateProduct(int $companyId, array $args): array
    {
        if (empty($args['name']) || empty($args['presentation'])) {
            return ['success' => false, 'message' => 'Me faltan datos para crear el producto.'];
        }

        $product = $this->productService->createProduct($companyId, [
            'name' => $args['name'],
            'presentation' => $args['presentation'],
            'type' => 'raw_material', // default from bot context usually
        ]);

        return [
            'success' => true,
            'message' => "Listo. Creé {$product->name} — {$product->presentation} como insumo. El código interno se generó automáticamente."
        ];
    }

    private function executeRegisterStock(int $companyId, int $userId, array $args): array
    {
        if (empty($args['product_name']) || empty($args['quantity'])) {
            return ['success' => false, 'message' => 'Me falta el producto o la cantidad.'];
        }

        $result = $this->findProduct($companyId, $args['product_name']);
        if (!$result['success']) {
            return $result;
        }

        $warehouse = Warehouse::where('company_id', $companyId)->first();
        if (!$warehouse) {
            return ['success' => false, 'message' => 'No encontré un depósito configurado en tu empresa.'];
        }

        $product = $result['product'];
        $quantity = (float) $args['quantity'];

        $this->stockService->registerMovement([
            'company_id' => $companyId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => MovementType::AdjustmentIn,
            'quantity_base' => $quantity, // Bot sends package quantities, and presentation handles this currently as 1 to 1 based on our previous Phase 2 rules
            'user_id' => $userId,
            'channel' => Channel::Web, // Treating as web for DB enum mapping compatibility
            'reason' => 'Ingreso por bot',
        ]);

        return [
            'success' => true,
            'message' => "Listo. Agregué {$quantity} {$product->presentation} de {$product->name} al stock."
        ];
    }

    private function executeAdjustStock(int $companyId, int $userId, array $args): array
    {
        return $this->executeRegisterStock($companyId, $userId, $args);
    }
    private function executeGetRecipe(int $companyId, array $args): array
    {
        $name = $args['product_name'] ?? null;
        if (!$name) {
            return ['success' => false, 'message' => '¿De qué producto querés consultar la receta?'];
        }

        $result = $this->findProduct($companyId, $name);
        if (!$result['success']) {
            return $result;
        }

        $product = $result['product'];
        $recipe = $product->recipe()->with('items.product.baseUnit')->first();
        
        if (!$recipe) {
            return ['success' => false, 'message' => "El producto {$product->name} no tiene una receta configurada."];
        }

        $msg = "La receta de {$product->name} rinde {$recipe->yield_quantity} {$product->presentation}:\n";
        foreach ($recipe->items as $item) {
            $msg .= "- {$item->product->name}: {$item->quantity_base} {$item->product->baseUnit->name}\n";
        }

        return ['success' => true, 'message' => trim($msg)];
    }

    private function executeCalculateProduction(int $companyId, array $args): array
    {
        $name = $args['product_name'] ?? null;
        $quantity = (float)($args['quantity'] ?? 0);
        
        if (!$name || $quantity <= 0) {
            return ['success' => false, 'message' => '¿Cuánto querés producir y de qué producto?'];
        }

        $result = $this->findProduct($companyId, $name);
        if (!$result['success']) {
            return $result;
        }

        $product = $result['product'];
        
        try {
            $calc = $this->calculatorService->calculateRequirements($product, $quantity);
            $msg = "Para producir {$quantity} u de {$product->name} necesitás:\n";
            foreach ($calc['items'] as $item) {
                $msg .= $this->formatItemLine($item);
            }
            return ['success' => true, 'message' => trim($msg)];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function executeGetMaxProduction(int $companyId, array $args): array
    {
        $name = $args['product_name'] ?? null;
        if (!$name) {
            return ['success' => false, 'message' => '¿De qué producto querés calcular la producción máxima?'];
        }

        // Para cálculos de producción priorizamos productos terminados. Así,
        // "cheddar" resuelve a "Hamburguesa cheddar" y no al fiambre cheddar.
        $products = Product::where('company_id', $companyId)
            ->where('type', 'finished_product')
            ->where('name', 'like', "%{$name}%")
            ->get();

        if ($products->count() === 0) {
            return ['success' => false, 'message' => "No encontré un producto terminado que coincida con '{$name}'."];
        }
        if ($products->count() > 1) {
            return ['success' => false, 'message' => "Encontré varios productos terminados que coinciden con '{$name}'. ¿Cuál querés?"];
        }

        $product = $products->first();

        try {
            $calc = $this->calculatorService->calculateMaxProducible($product);
            $units = (int) $calc['max_units'];
            $carros = intdiv($units, 288);
            $remainingAfterCars = $units % 288;
            $bandejas = intdiv($remainingAfterCars, 24);
            $looseUnits = $remainingAfterCars % 24;

            $parts = [];
            if ($carros > 0) $parts[] = "{$carros} carro" . ($carros === 1 ? '' : 's');
            if ($bandejas > 0) $parts[] = "{$bandejas} bandeja" . ($bandejas === 1 ? '' : 's');
            if ($looseUnits > 0) $parts[] = "{$looseUnits} u";
            if (empty($parts)) $parts[] = '0 carros';

            $message = "Con el stock actual podés producir hasta " . implode(', ', $parts)
                . " de {$product->name} ({$units} u en total).";

            if (!empty($calc['limiting_ingredient'])) {
                $message .= " El insumo limitante es {$calc['limiting_ingredient']->name}.";
            }

            return ['success' => true, 'message' => $message];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function executeGetMaxProduction(int $companyId, array $args): array
    {
        $name = $args['product_name'] ?? null;
        if (!$name) return ['success' => false, 'message' => '¿De qué producto querés calcular la producción máxima?'];

        $products = Product::where('company_id', $companyId)
            ->where('type', 'finished_product')
            ->where('name', 'like', "%{$name}%")->get();

        if ($products->count() === 0) return ['success' => false, 'message' => "No encontré un producto terminado que coincida con '{$name}'."];
        if ($products->count() > 1) return ['success' => false, 'message' => "Encontré varios productos terminados que coinciden con '{$name}'. ¿Cuál querés?"];

        $product = $products->first();
        try {
            $calc = $this->calculatorService->calculateMaxProducible($product);
            $units = (int) $calc['max_units'];
            $carros = intdiv($units, 288);
            $rest = $units % 288;
            $bandejas = intdiv($rest, 24);
            $sueltas = $rest % 24;
            $parts = [];
            if ($carros) $parts[] = "{$carros} carro" . ($carros === 1 ? '' : 's');
            if ($bandejas) $parts[] = "{$bandejas} bandeja" . ($bandejas === 1 ? '' : 's');
            if ($sueltas) $parts[] = "{$sueltas} u";
            if (!$parts) $parts[] = '0 carros';

            $message = "Con el stock actual podés producir hasta " . implode(', ', $parts) . " de {$product->name} ({$units} u en total).";
            if (!empty($calc['limiting_ingredient'])) $message .= " El insumo limitante es {$calc['limiting_ingredient']->name}.";
            return ['success' => true, 'message' => $message];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function executeCheckProduction(int $companyId, array $args): array
    {
        $name = $args['product_name'] ?? null;
        $quantity = (float)($args['quantity'] ?? 0);
        
        if (!$name || $quantity <= 0) {
            return ['success' => false, 'message' => '¿Cuánto querés producir y de qué producto?'];
        }

        $result = $this->findProduct($companyId, $name);
        if (!$result['success']) {
            return $result;
        }

        $product = $result['product'];
        
        try {
            $calc = $this->calculatorService->calculateRequirements($product, $quantity);
            if ($calc['can_produce']) {
                return ['success' => true, 'message' => "✅ Hay stock suficiente para producir {$quantity} u de {$product->name}."];
            } else {
                $msg = "❌ No alcanza el stock para producir {$quantity} u de {$product->name}.\nFalta:\n";
                foreach ($calc['items'] as $item) {
                    if ($item['missing'] > 0) {
                        $pres = $item['presentation'];
                        if ($pres['needs_ceil']) {
                            $msg .= "- {$item['ingredient']->name}: {$pres['missing_physical']} {$pres['name']}\n";
                        } else {
                            $msg .= "- {$item['ingredient']->name}: {$item['missing']} u\n";
                        }
                    }
                }
                return ['success' => true, 'message' => trim($msg)];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function executeGetMissingInputs(int $companyId, array $args): array
    {
        $name = $args['product_name'] ?? null;
        $quantity = (float)($args['quantity'] ?? 0);
        
        if (!$name || $quantity <= 0) {
            return ['success' => false, 'message' => '¿Cuánto querés producir y de qué producto?'];
        }

        $result = $this->findProduct($companyId, $name);
        if (!$result['success']) {
            return $result;
        }

        $product = $result['product'];
        
        try {
            $calc = $this->calculatorService->calculateRequirements($product, $quantity);
            if ($calc['can_produce']) {
                return ['success' => true, 'message' => "✅ No te falta nada. Hay stock suficiente para producir {$quantity} u de {$product->name}."];
            } else {
                $msg = "Para producir {$quantity} u de {$product->name} te falta:\n";
                foreach ($calc['items'] as $item) {
                    if ($item['missing'] > 0) {
                        $pres = $item['presentation'];
                        if ($pres['needs_ceil']) {
                            $msg .= "- {$item['ingredient']->name}: {$pres['missing_physical']} {$pres['name']}\n";
                        } else {
                            $msg .= "- {$item['ingredient']->name}: {$item['missing']} u\n";
                        }
                    }
                }
                return ['success' => true, 'message' => trim($msg)];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─── STOCK ADJUSTMENT ACTIONS ──────────────────────────────────────────────

    /**
     * set_stock: "El stock de bacon es 12 piezas"
     * Replaces current stock by calculating the diff and applying a movement.
     */
    private function executeSetStock(int $companyId, int $userId, array $args): array
    {
        $warehouse = Warehouse::where('company_id', $companyId)->first();
        if (!$warehouse) {
            return ['success' => false, 'message' => 'No encontré un depósito configurado.'];
        }

        // Handle bulk items
        if (!empty($args['items']) && is_array($args['items'])) {
            $messages = [];
            DB::transaction(function () use ($companyId, $userId, $warehouse, $args, &$messages) {
                foreach ($args['items'] as $item) {
                    $res = $this->applySingleSetStock($companyId, $userId, $warehouse, $item['product_name'] ?? '', $item['quantity'] ?? 0, $item['presentation_name'] ?? null);
                    $messages[] = $res['message'];
                }
            });
            return ['success' => true, 'message' => implode("\n", $messages)];
        }

        // Handle single item
        if (empty($args['product_name']) || !isset($args['quantity'])) {
            return ['success' => false, 'message' => 'Necesito el producto y la cantidad nueva.'];
        }

        return $this->applySingleSetStock($companyId, $userId, $warehouse, $args['product_name'], $args['quantity'], $args['presentation_name'] ?? null);
    }

    private function applySingleSetStock(int $companyId, int $userId, Warehouse $warehouse, string $productName, float $quantity, ?string $presentationName): array
    {
        $result = $this->findProduct($companyId, $productName);
        if (!$result['success']) {
            return $result;
        }

        $product = $result['product'];
        $newQtyBase = $this->resolveBaseQuantity($product, $quantity, $presentationName);
        $currentQty = (float) Stock::where('product_id', $product->id)->where('company_id', $companyId)->sum('quantity');
        $diff = $newQtyBase - $currentQty;

        if (abs($diff) < 0.001) {
            return ['success' => true, 'message' => "- El stock de {$product->name} ya estaba en {$newQtyBase} u."];
        }

        $this->stockService->registerMovement([
            'company_id' => $companyId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => $diff > 0 ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
            'quantity_base' => abs($diff),
            'user_id' => $userId,
            'channel' => Channel::Telegram,
            'reason' => 'Ajuste manual: establecer stock',
        ]);

        return ['success' => true, 'message' => "✅ Stock de {$product->name} actualizado a {$newQtyBase} u."];
    }

    /**
     * add_stock: "Agregá 3 barras de cheddar"
     */
    private function executeAddStock(int $companyId, int $userId, array $args): array
    {
        if (empty($args['product_name']) || empty($args['quantity'])) {
            return ['success' => false, 'message' => 'Necesito el producto y la cantidad a sumar.'];
        }

        $result = $this->findProduct($companyId, $args['product_name']);
        if (!$result['success']) {
            return $result;
        }

        $warehouse = Warehouse::where('company_id', $companyId)->first();
        if (!$warehouse) {
            return ['success' => false, 'message' => 'No encontré un depósito configurado.'];
        }

        $product = $result['product'];
        $qtyBase = $this->resolveBaseQuantity($product, (float) $args['quantity'], $args['presentation_name'] ?? null);

        $this->stockService->registerMovement([
            'company_id' => $companyId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => MovementType::AdjustmentIn,
            'quantity_base' => $qtyBase,
            'user_id' => $userId,
            'channel' => Channel::Telegram,
            'reason' => 'Ajuste manual: ingreso de stock',
        ]);

        $newTotal = (float) Stock::where('product_id', $product->id)->where('company_id', $companyId)->sum('quantity');
        return ['success' => true, 'message' => "✅ Sumé {$qtyBase} u de {$product->name}. Stock actual: {$newTotal} u."];
    }

    /**
     * remove_stock: "Usamos 7 piezas de bacon"
     */
    private function executeRemoveStock(int $companyId, int $userId, array $args): array
    {
        if (empty($args['product_name']) || empty($args['quantity'])) {
            return ['success' => false, 'message' => 'Necesito el producto y la cantidad a restar.'];
        }

        $result = $this->findProduct($companyId, $args['product_name']);
        if (!$result['success']) {
            return $result;
        }

        $warehouse = Warehouse::where('company_id', $companyId)->first();
        if (!$warehouse) {
            return ['success' => false, 'message' => 'No encontré un depósito configurado.'];
        }

        $product = $result['product'];
        $qtyBase = $this->resolveBaseQuantity($product, (float) $args['quantity'], $args['presentation_name'] ?? null);

        $this->stockService->registerMovement([
            'company_id' => $companyId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => MovementType::AdjustmentOut,
            'quantity_base' => $qtyBase,
            'user_id' => $userId,
            'channel' => Channel::Telegram,
            'reason' => 'Ajuste manual: consumo informado',
        ]);

        $newTotal = (float) Stock::where('product_id', $product->id)->where('company_id', $companyId)->sum('quantity');
        return ['success' => true, 'message' => "✅ Desconté {$qtyBase} u de {$product->name}. Stock actual: {$newTotal} u."];
    }

    // ─── PRODUCTION ────────────────────────────────────────────────────────────

    /**
     * register_production: "Terminamos 3 carros de cheddar"
     * Args: product_name, quantity (direct hamburguesas), or carros + bandejas.
     */
    private function executeRegisterProduction(User $user, array $args): array
    {
        if (empty($args['product_name'])) {
            return ['success' => false, 'message' => '¿De qué producto registramos la producción?'];
        }

        $result = $this->findProduct($user->company_id, $args['product_name']);
        if (!$result['success']) {
            return $result;
        }

        $product = $result['product'];

        // Resolve quantity from carros/bandejas or direct units
        $quantity = 0.0;
        if (!empty($args['carros']) || !empty($args['bandejas'])) {
            $quantity = ProductionOrderService::toHamburguesas(
                (float) ($args['carros'] ?? 0),
                (float) ($args['bandejas'] ?? 0),
            );
        } elseif (!empty($args['quantity'])) {
            $quantity = (float) $args['quantity'];
        }

        if ($quantity <= 0) {
            return ['success' => false, 'message' => '¿Cuántas unidades producimos?'];
        }

        // Actual consumptions if the user informed them
        $actualConsumptions = [];
        if (!empty($args['actual_consumptions']) && is_array($args['actual_consumptions'])) {
            foreach ($args['actual_consumptions'] as $item) {
                if (empty($item['product_name']) || !isset($item['quantity'])) continue;
                
                $pr = $this->findProduct($user->company_id, $item['product_name']);
                if ($pr['success']) {
                    $baseQty = $this->resolveBaseQuantity($pr['product'], (float) $item['quantity'], $item['presentation_name'] ?? null);
                    $actualConsumptions[$pr['product']->id] = $baseQty;
                }
            }
        }

        try {
            $result = $this->productionOrderService->registerProduction(
                $user, $product, $quantity, $actualConsumptions, Channel::Telegram
            );

            $msg = "✅ Producción registrada: {$quantity} u de {$product->name} (Orden #{$result['order']->id}).";
            if (!empty($result['warnings'])) {
                $msg .= "\n" . implode("\n", $result['warnings']);
            }
            return ['success' => true, 'message' => $msg];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─── HELPERS ───────────────────────────────────────────────────────────────

    /**
     * Format a single calculator item line using presentation data when available.
     * Applies ceil() for physically indivisible units (cajas, barras, piezas...).
     */
    private function formatItemLine(array $item): string
    {
        $name = $item['ingredient']->name;
        $pres = $item['presentation'];

        if ($pres['needs_ceil']) {
            $physicalRequired = $pres['required_physical'];
            $presName = $pres['name'];
            $baseRequired = $item['required'];

            // Show both: "12 cajas (288 u)"
            $line = "- {$name}: {$physicalRequired} {$presName} ({$baseRequired} u)";

            // If not a whole number, show breakdown
            $complete = (int) $pres['complete_units'];
            $remainder = (int) $pres['remainder_units'];
            if ($remainder > 0) {
                $line .= " — {$complete} completas + 1 incompleta ({$remainder} u)";
            }

            return $line . "\n";
        }

        return "- {$name}: {$item['required']} u\n";
    }

    /**
     * Resolve base quantity from a physical presentation quantity.
     * E.g. "3 barras" of cheddar (factor=200) → 600 base units.
     * If presentation_name is null or not found, treats quantity as base units.
     *
     * NOTE: For bacon/lomito/queso/jamón, conversion factors are currently
     * unconfirmed (seeder placeholder values). This is documented intentionally.
     */
    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.00001) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function formatNumber(float $value): string
    {
        return abs($value - round($value)) < 0.00001
            ? (string) (int) round($value)
            : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function resolveBaseQuantity(Product $product, float $quantity, ?string $presentationName): float
    {
        if ($presentationName) {
            // Block unconfirmed conversions
            $unconfirmedNames = ['Bacon', 'Lomito', 'Queso', 'Jamón', 'Cheddar'];
            foreach ($unconfirmedNames as $name) {
                if (stripos($product->name, $name) !== false && (stripos($presentationName, 'barra') !== false || stripos($presentationName, 'pieza') !== false)) {
                    throw new Exception("No puedo convertir '{$presentationName}' a unidades base para {$product->name} porque el rendimiento real todavía no está confirmado en el sistema. Por favor, indicá la cantidad en unidades (fetas).");
                }
            }

            $pres = $product->presentations()
                ->where('name', 'like', "%{$presentationName}%")
                ->first();

            if ($pres && (float) $pres->conversion_factor > 1) {
                return $quantity * (float) $pres->conversion_factor;
            }
        }
        return $quantity;
    }
}