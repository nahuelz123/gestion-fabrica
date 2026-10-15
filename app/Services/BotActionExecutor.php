<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\ProductionOrder;
use App\Models\VendingSale;
use App\Models\Supplier;
use App\Models\ProductAlias;
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
        if (!$user->isActive() || !$user->canUseBotAction($actionName)) {
            return ['success' => false, 'message' => 'No tenés permiso para esa consulta u operación.'];
        }

        try {
            return DB::transaction(function () use ($actionName, $args, $companyId, $user) {
                return match ($actionName) {
                    'get_stock' => $this->executeGetStock($companyId, $args),
                    'get_low_stock' => $this->executeGetLowStock($companyId),
                    'get_expiring_products' => $this->executeGetExpiringProducts($companyId, $args),
                    'get_stock_movements' => $this->executeGetStockMovements($companyId, $args),
                    'get_sales_summary' => $this->executeGetSalesSummary($companyId, $args),
                    'get_purchases_summary' => $this->executeGetPurchasesSummary($companyId, $args),
                    'get_business_summary' => $this->executeGetBusinessSummary($companyId, $args),
                    'get_suppliers' => $this->executeGetSuppliers($companyId),
                    'compare_max_production' => $this->executeCompareMaxProduction($companyId, $args),
                    'get_recent_productions' => $this->executeGetRecentProductions($companyId, $args),
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
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'Decime qué producto querés usar.'];
        }

        // El lenguaje natural suele usar nombres cortos como "bacon". Si existe
        // un producto con ese nombre exacto, no debe competir con "Hamburguesa bacon".
        $exact = Product::where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($exact) {
            return ['success' => true, 'product' => $exact];
        }

        $alias = ProductAlias::where('company_id', $companyId)
            ->whereRaw('LOWER(alias) = ?', [mb_strtolower($name)])
            ->with('product')
            ->first();
        if ($alias?->product) {
            return ['success' => true, 'product' => $alias->product];
        }

        $products = Product::where('company_id', $companyId)
            ->where(function ($query) use ($name) {
                $query->where('name', 'like', "%{$name}%")
                    ->orWhereHas('aliases', fn ($aliases) => $aliases->where('alias', 'like', "%{$name}%"));
            })
            ->orderBy('name')
            ->limit(12)
            ->get();

        if ($products->count() === 0) {
            return ['success' => false, 'message' => "No encontré ningún producto llamado '{$name}' en tu empresa."];
        }

        if ($products->count() > 1) {
            $options = $products->values()->map(
                fn ($product, $index) => ($index + 1) . '. ' . $product->name
            )->implode("\n");

            return [
                'success' => false,
                'message' => "Encontré más de una opción para '{$name}':\n{$options}\n\nDecime el nombre o el número y sigo con lo que estábamos haciendo.",
                'candidates' => $products->pluck('name')->values()->all(),
            ];
        }

        return ['success' => true, 'product' => $products->first()];
    }

    private function executeGetStock(int $companyId, array $args): array
    {
        $name = $args['product_name'] ?? null;
        if (!$name || in_array(mb_strtolower(trim((string) $name)), ['all', 'todo', 'todos', 'inventario', 'general'], true)) {
            $products = Product::query()->where('company_id', $companyId)
                ->where('status', 'active')->with('baseUnit:id,abbreviation')
                ->withSum('stocks as available_stock', 'quantity')
                ->orderBy('name')->limit(35)->get();
            if ($products->isEmpty()) return ['success' => true, 'message' => 'No hay productos activos registrados.'];
            $lines = ['Stock registrado (todos los depósitos):'];
            foreach ($products as $product) {
                $lines[] = '- ' . $product->name . ': ' .
                    $this->formatNumber((float) ($product->available_stock ?? 0)) .
                    ' ' . ($product->baseUnit?->abbreviation ?? 'u');
            }
            if (Product::where('company_id', $companyId)->where('status', 'active')->count() > 35) {
                $lines[] = 'Mostrando los primeros 35 productos. Pedime uno por nombre para ver su detalle.';
            }
            return ['success' => true, 'message' => implode("\n", $lines)];
        }

        $result = $this->findProduct($companyId, (string) $name);
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
        $products = Product::query()->where('company_id', $companyId)
            ->where('status', 'active')->with('baseUnit:id,abbreviation')
            ->withSum('stocks as stock_total', 'quantity')
            ->havingRaw('COALESCE(stock_total, 0) <= 0 OR (min_stock IS NOT NULL AND min_stock > 0 AND COALESCE(stock_total, 0) <= min_stock)')
            ->orderBy('name')->limit(25)->get();

        if ($products->isEmpty()) return ['success' => true, 'message' => 'No encontré productos activos sin stock o por debajo de su mínimo.'];
        $lines = ['Productos sin stock o con stock bajo:'];
        foreach ($products as $product) {
            $lines[] = '- ' . $product->name . ': ' . $this->formatNumber((float) ($product->stock_total ?? 0)) . ' ' .
                ($product->baseUnit?->abbreviation ?? 'u') .
                ((float) $product->min_stock > 0 ? ' (mínimo: ' . $this->formatNumber((float) $product->min_stock) . ')' : ' (sin stock)');
        }
        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    private function readPeriod(array $args): int
    {
        return max(1, min(365, (int) ($args['days'] ?? 30)));
    }

    private function executeGetExpiringProducts(int $companyId, array $args): array
    {
        $days = max(0, min(365, (int) ($args['days'] ?? 7)));
        $stocks = Stock::query()->where('company_id', $companyId)->where('quantity', '>', 0)
            ->whereNotNull('lot_id')->whereHas('lot', function ($query) use ($days) {
                $query->whereNotNull('expiration_date')
                    ->whereDate('expiration_date', '<=', now()->addDays($days)->toDateString());
            })
            ->with(['product:id,name', 'lot:id,lot_code,expiration_date,status', 'warehouse:id,name'])
            ->orderBy('product_id')->limit(30)->get();
        if ($stocks->isEmpty()) return ['success' => true, 'message' => "No hay lotes con existencia vencidos o por vencer en los próximos {$days} días."];
        $lines = ["Lotes vencidos o que vencen dentro de {$days} días:"];
        foreach ($stocks as $stock) {
            $expired = $stock->lot->expiration_date->lt(now()->startOfDay());
            $lines[] = '- ' . ($stock->product?->name ?? 'Producto') . ' | lote ' . $stock->lot->lot_code .
                ' | ' . ($expired ? 'VENCIDO ' : 'vence ') . $stock->lot->expiration_date->format('d/m/Y') .
                ' | ' . $this->formatNumber((float) $stock->quantity) . ' u | ' . ($stock->warehouse?->name ?? 'Depósito');
        }
        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    private function executeGetStockMovements(int $companyId, array $args): array
    {
        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));
        $query = StockMovement::where('company_id', $companyId)
            ->with(['product:id,name', 'warehouse:id,name', 'user:id,name', 'lot:id,lot_code']);
        if (!empty($args['product_name']) && !in_array(mb_strtolower((string) $args['product_name']), ['all', 'todo', 'todos'], true)) {
            $resolved = $this->findProduct($companyId, (string) $args['product_name']);
            if (!$resolved['success']) return $resolved;
            $query->where('product_id', $resolved['product']->id);
        }
        if (!empty($args['days'])) $query->whereDate('created_at', '>=', now()->subDays($this->readPeriod($args))->toDateString());
        $movements = $query->latest('created_at')->limit($limit)->get();
        if ($movements->isEmpty()) return ['success' => true, 'message' => 'No hay movimientos que coincidan con esa consulta.'];
        $lines = ['Últimos movimientos registrados:'];
        foreach ($movements as $movement) {
            $amount = (float) $movement->quantity_base;
            $lines[] = '- ' . $movement->created_at?->format('d/m H:i') . ' | ' .
                ($movement->product?->name ?? 'Producto') . ' | ' . $movement->type->label() .
                ' ' . ($amount > 0 ? '+' : '') . $this->formatNumber($amount) . ' u' .
                ' | ' . ($movement->warehouse?->name ?? 'Depósito') .
                ' | ' . ($movement->user?->name ?? 'Sistema');
        }
        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    private function executeGetSalesSummary(int $companyId, array $args): array
    {
        $days = $this->readPeriod($args);
        $base = VendingSale::where('company_id', $companyId)
            ->whereDate('sold_at', '>=', now()->subDays($days - 1)->toDateString());
        $approved = (clone $base)->whereIn('status', ['approved', 'partially_refunded']);
        $count = (clone $approved)->count();
        $net = (float) (clone $approved)->sum('gross_amount') - (float) (clone $approved)->sum('refunded_amount');
        $refunds = (clone $base)->whereIn('status', ['refunded', 'partially_refunded'])->count();
        return ['success' => true, 'message' => "Ventas de máquinas en los últimos {$days} días (incluido hoy): {$count} ventas válidas. Importe neto registrado: $" .
            number_format(max(0, $net), 2, ',', '.') . ". Ventas con devolución total o parcial: {$refunds}. Estos datos corresponden a ventas registradas, no a una conciliación bancaria."];
    }


    private function executeGetRecentProductions(int $companyId, array $args): array
    {
        $query = ProductionOrder::where('company_id', $companyId)
            ->where('status', 'completed')
            ->with('product:id,name');
        $name = trim((string) ($args['product_name'] ?? ''));
        if ($name !== '' && !in_array(mb_strtolower($name), ['all', 'todos', 'todo'], true)) {
            $query->whereHas('product', fn ($product) => $product->where('name', 'like', '%' . $name . '%'));
        }
        $orders = $query->orderByDesc('id')->limit(max(1, min(15, (int) ($args['limit'] ?? 8))))->get();
        if ($orders->isEmpty()) {
            return ['success' => true, 'message' => 'No encontré órdenes de producción confirmadas para esa consulta.'];
        }
        $lines = ['Órdenes de producción confirmadas:'];
        foreach ($orders as $order) {
            $lines[] = '- #' . $order->id . ': ' . ($order->product?->name ?? 'Producto') .
                ', ' . $this->formatNumber((float) $order->target_quantity) . ' unidades (' .
                $order->created_at->format('d/m H:i') . ')';
        }
        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    private function executeCompareMaxProduction(int $companyId, array $args): array
    {
        $names = array_values(array_unique(array_filter((array) ($args['product_names'] ?? []), 'is_string')));
        if (count($names) < 2 || count($names) > 4) {
            return ['success' => false, 'message' => 'Decime las dos variedades de hamburguesas que querés comparar.'];
        }

        $warehouseId = $this->productionWarehouseId($companyId);
        if ($warehouseId <= 0) return ['success' => false, 'message' => 'No hay un depósito de producción configurado.'];

        $lines = ['Máximo de producción por variedad, calculado por separado:'];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') continue;
            $options = Product::where('company_id', $companyId)
                ->where('type', 'finished_product')->where('status', 'active')
                ->where(function ($query) use ($name) {
                    $query->where('name', 'like', '%' . $name . '%')
                        ->orWhereHas('aliases', fn ($aliases) => $aliases->where('alias', 'like', '%' . $name . '%'));
                })->limit(5)->get();

            if ($options->count() !== 1) {
                $answer = $options->isEmpty()
                    ? "No encontré una hamburguesa para '{$name}'."
                    : "Hay varias hamburguesas que coinciden con '{$name}': " . $options->pluck('name')->implode(', ') . '.';
                return ['success' => false, 'message' => $answer . ' Decime el nombre completo para calcularlo.'];
            }
            $product = $options->first();
            try {
                $max = $this->calculatorService->calculateMaxProducible($product, [], $warehouseId);
            } catch (Exception $exception) {
                return ['success' => false, 'message' => 'No pude calcular ' . $product->name . ': ' . $exception->getMessage()];
            }
            $units = max(0, (int) $max['max_units']);
            $cars = intdiv($units, ProductionOrderService::HAMBURGUESAS_PER_CARRO);
            $remainder = $units % ProductionOrderService::HAMBURGUESAS_PER_CARRO;
            $trays = intdiv($remainder, ProductionOrderService::HAMBURGUESAS_PER_BANDEJA);
            $loose = $remainder % ProductionOrderService::HAMBURGUESAS_PER_BANDEJA;
            $parts = [];
            if ($cars > 0) $parts[] = $cars . ' ' . ($cars === 1 ? 'carro' : 'carros');
            if ($trays > 0) $parts[] = $trays . ' ' . ($trays === 1 ? 'bandeja' : 'bandejas');
            if ($loose > 0 || !$parts) $parts[] = $loose . ' unidades';

            $line = '- ' . $product->name . ': ' . implode(', ', $parts) . " ({$units} unidades)";
            if (!empty($max['limiting_ingredient'])) {
                $line .= '. Insumo limitante: ' . $max['limiting_ingredient']->name;
            }
            $lines[] = $line;
        }

        $lines[] = 'Ojo: son máximos individuales. Como las variedades comparten insumos, no podés sumar esos resultados. Para producir ambas en conjunto necesito un plan con las cantidades de cada una.';
        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    private function executeGetPurchasesSummary(int $companyId, array $args): array
    {
        $days = $this->readPeriod($args);
        $query = Purchase::where('company_id', $companyId)
            ->whereDate('purchase_date', '>=', now()->subDays($days - 1)->toDateString());
        $confirmed = (clone $query)->where('status', 'confirmed');
        $confirmedIds = (clone $confirmed)->select('id');
        $total = (float) \App\Models\PurchaseItem::whereIn('purchase_id', $confirmedIds)
            ->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as total')->value('total');
        $confirmedCount = (clone $confirmed)->count();
        $draftCount = (clone $query)->where('status', 'draft')->count();
        return [
            'success' => true,
            'message' => "Compras en los últimos {$days} días (incluido hoy): {$confirmedCount} confirmadas, {$draftCount} borradores. " .
                'Importe estimado de compras confirmadas: ARS ' . number_format($total, 2, ',', '.') . '.',
        ];
    }

    private function executeGetSuppliers(int $companyId): array
    {
        $suppliers = Supplier::where('company_id', $companyId)->orderBy('name')
            ->limit(30)->get(['name', 'phone', 'email']);
        if ($suppliers->isEmpty()) {
            return ['success' => true, 'message' => 'No hay proveedores cargados.'];
        }
        $lines = ['Proveedores registrados:'];
        foreach ($suppliers as $supplier) {
            $contact = array_filter([$supplier->phone, $supplier->email]);
            $lines[] = '- ' . $supplier->name . ($contact ? ' — ' . implode(' / ', $contact) : '');
        }
        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    private function executeGetBusinessSummary(int $companyId, array $args): array
    {
        $days = $this->readPeriod($args);
        $products = Product::where('company_id', $companyId)->where('status', 'active')->count();
        $orders = ProductionOrder::where('company_id', $companyId)
            ->whereDate('created_at', '>=', now()->subDays($days - 1)->toDateString())
            ->where('status', 'completed')->count();
        $sales = $this->executeGetSalesSummary($companyId, ['days' => $days])['message'];
        $purchases = $this->executeGetPurchasesSummary($companyId, ['days' => $days])['message'];

        return [
            'success' => true,
            'message' => "Resumen de la fábrica: {$products} productos activos y {$orders} órdenes de producción completadas en los últimos {$days} días.\n{$purchases}\n{$sales}",
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

    /**
     * Telegram registers production in the first configured warehouse.
     * Reports and simulations must use that same warehouse.
     */
    private function productionWarehouseId(int $companyId): int
    {
        return (int) Warehouse::where('company_id', $companyId)->orderBy('id')->value('id');
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
            $calc = $this->calculatorService->calculateRequirements($product, $quantity, $this->productionWarehouseId($companyId));
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
            // Simulaciones tipo "si agrego 1000 bolsitas" no modifican el stock real.
            // Gemini envía esos cambios en hypothetical_stock_additions.
            $stockAdditions = [];
            $simulatedDescriptions = [];

            foreach (($args['hypothetical_stock_additions'] ?? []) as $addition) {
                $ingredientName = $addition['product_name'] ?? null;
                $quantity = (float) ($addition['quantity'] ?? 0);
                if (!$ingredientName || $quantity <= 0) {
                    continue;
                }

                $ingredientResult = $this->findProduct($companyId, $ingredientName);
                if (!$ingredientResult['success']) {
                    return $ingredientResult;
                }

                $ingredient = $ingredientResult['product'];
                $baseQuantity = $this->resolveBaseQuantity(
                    $ingredient,
                    $quantity,
                    $addition['presentation_name'] ?? null
                );

                $stockAdditions[$ingredient->id] = ($stockAdditions[$ingredient->id] ?? 0) + $baseQuantity;
                $simulatedDescriptions[] = $this->formatNumber($quantity) . ' ' . ($addition['presentation_name'] ?? $ingredient->presentation) . ' de ' . $ingredient->name;
            }

            $calc = $this->calculatorService->calculateMaxProducible($product, $stockAdditions, $this->productionWarehouseId($companyId));
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

            $prefix = empty($simulatedDescriptions)
                ? "Con el stock actual"
                : "Simulando +" . implode(', +', $simulatedDescriptions) . " sobre el stock actual";
            $message = $prefix . " podés producir hasta " . implode(', ', $parts)
                . " de {$product->name} ({$units} u en total).";

            if (!empty($calc['limiting_ingredient'])) {
                $message .= " El insumo limitante es {$calc['limiting_ingredient']->name}.";
            }

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
            $calc = $this->calculatorService->calculateRequirements($product, $quantity, $this->productionWarehouseId($companyId));
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
            $calc = $this->calculatorService->calculateRequirements($product, $quantity, $this->productionWarehouseId($companyId));
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
                $user, $product, $quantity, $actualConsumptions, Channel::Telegram, $this->productionWarehouseId($user->company_id)
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
     * Bacon y lomito se cuentan por pieza y admiten fracciones (7.5 piezas).
     * Si presentation_name no existe, la cantidad ya se considera unidad base.
     */
    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.00001) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }


    private function resolveBaseQuantity(Product $product, float $quantity, ?string $presentationName): float
    {
        if ($presentationName) {
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
