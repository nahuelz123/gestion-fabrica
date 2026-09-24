<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Str;

class FlexibleBotActionExecutor extends BotActionExecutor
{
    public function __construct(
        ProductService $productService,
        StockService $stockService,
        ProductionCalculatorService $calculatorService,
        ProductionOrderService $productionOrderService,
    ) {
        parent::__construct(
            $productService,
            $stockService,
            $calculatorService,
            $productionOrderService,
        );
    }

    public function execute(User $user, string $chatId, array $action, array $context): array
    {
        $name = $action['name'] ?? null;
        $args = is_array($action['arguments'] ?? null) ? $action['arguments'] : [];
        $companyId = $user->company_id;

        if ($name === 'get_stock' && $this->meansAllProducts($args['product_name'] ?? null)) {
            return $this->executeGetAllStock($companyId);
        }

        if ($name === 'get_low_stock') {
            return $this->executeGetLowStock($companyId);
        }

        if ($name === 'get_expiring_products') {
            return $this->executeGetExpiringProducts($companyId, (int) ($args['days'] ?? 30));
        }

        if ($name === 'get_stock_movements') {
            return $this->executeGetStockMovements($companyId, (int) ($args['limit'] ?? 10));
        }

        if ($name === 'plan_production') {
            return $this->executePlanProduction($companyId, $args);
        }

        if (!empty($args['product_name'])) {
            $product = $this->resolveProduct($companyId, (string) $args['product_name']);
            if ($product) {
                $args['product_name'] = $product->name;
                $args['presentation_name'] = $this->resolvePresentationName(
                    $product,
                    $args['presentation_name'] ?? null
                );
            }
        }

        foreach (['items', 'actual_consumptions', 'hypothetical_stock_additions', 'production_items'] as $listKey) {
            if (empty($args[$listKey]) || !is_array($args[$listKey])) {
                continue;
            }

            foreach ($args[$listKey] as $index => $item) {
                if (!is_array($item) || empty($item['product_name'])) {
                    continue;
                }

                $product = $listKey === 'production_items'
                    ? $this->resolveFinishedProduct($companyId, (string) $item['product_name'])
                    : $this->resolveProduct($companyId, (string) $item['product_name']);

                if (!$product) {
                    continue;
                }

                $args[$listKey][$index]['product_name'] = $product->name;
                $args[$listKey][$index]['presentation_name'] = $this->resolvePresentationName(
                    $product,
                    $item['presentation_name'] ?? null
                );
            }
        }

        // Cuando el usuario informa una presentación física (cajas, paquetes, barras),
        // usamos add_stock porque ese flujo convierte la presentación a unidades base.
        if (in_array($name, ['register_stock', 'adjust_stock'], true)
            && !empty($args['presentation_name'])) {
            $name = 'add_stock';
        }

        $action['name'] = $name;
        $action['arguments'] = $args;

        return parent::execute($user, $chatId, $action, $context);
    }

    private function executeGetAllStock(int $companyId): array
    {
        $products = Product::where('company_id', $companyId)
            ->with(['baseUnit', 'presentations', 'stocks'])
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            return ['success' => true, 'message' => 'No hay productos cargados todavía.'];
        }

        $raw = [];
        $finished = [];

        foreach ($products as $product) {
            $qty = (float) $product->stocks->sum('quantity');
            $line = $this->stockLine($product, $qty);
            $type = $product->type instanceof \BackedEnum ? $product->type->value : (string) $product->type;

            if ($type === 'finished_product') {
                $finished[] = $line;
            } else {
                $raw[] = $line;
            }
        }

        $lines = ['📦 Stock actual'];
        if ($raw) {
            $lines[] = "\nInsumos:";
            array_push($lines, ...$raw);
        }
        if ($finished) {
            $lines[] = "\nProductos terminados:";
            array_push($lines, ...$finished);
        }

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    private function stockLine(Product $product, float $qty): string
    {
        $baseUnit = $product->baseUnit->abbreviation ?? 'u';
        $line = "- {$product->name}: {$this->formatNumber($qty)} {$baseUnit}";

        $presentation = $product->presentations
            ->firstWhere('is_purchase_default', true)
            ?? $product->presentations->first();

        if ($presentation && (float) $presentation->conversion_factor > 1) {
            $factor = (float) $presentation->conversion_factor;
            $physical = $qty / $factor;
            $complete = (int) floor($physical);
            $remainder = $qty - ($complete * $factor);

            if (abs($remainder) < 0.00001) {
                $line .= " ({$complete} {$presentation->name})";
            } else {
                $line .= " ({$this->formatNumber($physical)} {$presentation->name})";
            }
        }

        return $line;
    }

    private function executeGetLowStock(int $companyId): array
    {
        $products = Product::where('company_id', $companyId)
            ->where('min_stock', '>', 0)
            ->with(['baseUnit', 'stocks'])
            ->orderBy('name')
            ->get();

        $low = [];
        foreach ($products as $product) {
            $qty = (float) $product->stocks->sum('quantity');
            $min = (float) $product->min_stock;
            if ($qty <= $min) {
                $unit = $product->baseUnit->abbreviation ?? 'u';
                $low[] = "- {$product->name}: {$this->formatNumber($qty)} {$unit} (mínimo {$this->formatNumber($min)})";
            }
        }

        if (!$low) {
            return [
                'success' => true,
                'message' => $products->isEmpty()
                    ? 'Todavía no hay mínimos de stock configurados.'
                    : '✅ No hay productos por debajo del stock mínimo.',
            ];
        }

        return ['success' => true, 'message' => "⚠️ Stock bajo:\n" . implode("\n", $low)];
    }

    private function executeGetExpiringProducts(int $companyId, int $days): array
    {
        $days = max(1, min($days ?: 30, 365));
        $until = now()->addDays($days)->endOfDay();

        $lots = StockLot::where('company_id', $companyId)
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<=', $until)
            ->with('product')
            ->orderBy('expiration_date')
            ->get();

        $lines = [];
        foreach ($lots as $lot) {
            $remaining = (float) Stock::where('company_id', $companyId)
                ->where('lot_id', $lot->id)
                ->sum('quantity');

            if ($remaining <= 0) {
                continue;
            }

            $date = $lot->expiration_date?->format('d/m/Y') ?? '-';
            $prefix = $lot->expiration_date && $lot->expiration_date->isPast() ? 'VENCIDO' : 'vence';
            $lines[] = "- {$lot->product->name} · lote {$lot->lot_code}: {$this->formatNumber($remaining)} u · {$prefix} {$date}";
        }

        if (!$lines) {
            return ['success' => true, 'message' => "✅ No hay lotes con stock que venzan en los próximos {$days} días."];
        }

        return ['success' => true, 'message' => "📅 Vencimientos próximos:\n" . implode("\n", $lines)];
    }

    private function executeGetStockMovements(int $companyId, int $limit): array
    {
        $limit = max(1, min($limit ?: 10, 30));

        $movements = StockMovement::where('company_id', $companyId)
            ->with(['product', 'user'])
            ->latest('created_at')
            ->limit($limit)
            ->get();

        if ($movements->isEmpty()) {
            return ['success' => true, 'message' => 'Todavía no hay movimientos de stock registrados.'];
        }

        $lines = ['🧾 Últimos movimientos:'];
        foreach ($movements as $movement) {
            $qty = (float) $movement->quantity_base;
            $sign = $qty > 0 ? '+' : '';
            $when = $movement->created_at?->format('d/m H:i') ?? '';
            $who = $movement->user?->name ? " · {$movement->user->name}" : '';
            $reason = $movement->reason ? " · {$movement->reason}" : '';
            $lines[] = "- {$when} · {$movement->product->name}: {$sign}{$this->formatNumber($qty)} u{$who}{$reason}";
        }

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    private function executePlanProduction(int $companyId, array $args): array
    {
        $items = $args['production_items'] ?? $args['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            return ['success' => false, 'message' => 'Decime qué productos y cantidades querés incluir en el plan de producción.'];
        }

        $requirements = [];
        $planLines = [];

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['product_name'])) {
                continue;
            }

            $product = $this->resolveFinishedProduct($companyId, (string) $item['product_name']);
            if (!$product) {
                return ['success' => false, 'message' => "No pude identificar el producto terminado '{$item['product_name']}'."];
            }

            $units = (float) ($item['quantity'] ?? 0);
            if ($units <= 0) {
                $units = ((float) ($item['carros'] ?? 0) * 288)
                    + ((float) ($item['bandejas'] ?? 0) * 24);
            }

            if ($units <= 0) {
                return ['success' => false, 'message' => "Me falta la cantidad a producir de {$product->name}."];
            }

            $recipe = $product->recipe()->with('items.product')->first();
            if (!$recipe || (float) $recipe->yield_quantity <= 0) {
                return ['success' => false, 'message' => "{$product->name} no tiene una receta válida configurada."];
            }

            $planLines[] = "- {$product->name}: {$this->formatProductionUnits($units)}";
            $multiplier = $units / (float) $recipe->yield_quantity;

            foreach ($recipe->items as $recipeItem) {
                $id = $recipeItem->product_id;
                if (!isset($requirements[$id])) {
                    $requirements[$id] = [
                        'product' => $recipeItem->product,
                        'required' => 0.0,
                    ];
                }
                $requirements[$id]['required'] += (float) $recipeItem->quantity_base * $multiplier;
            }
        }

        if (!$requirements) {
            return ['success' => false, 'message' => 'No pude calcular los insumos del plan.'];
        }

        $stockAdditions = [];
        foreach (($args['hypothetical_stock_additions'] ?? []) as $addition) {
            if (!is_array($addition) || empty($addition['product_name'])) {
                continue;
            }

            $product = $this->resolveProduct($companyId, (string) $addition['product_name']);
            if (!$product) {
                continue;
            }

            $qty = (float) ($addition['quantity'] ?? 0);
            $presentationName = $this->resolvePresentationName($product, $addition['presentation_name'] ?? null);
            $stockAdditions[$product->id] = ($stockAdditions[$product->id] ?? 0)
                + $this->toBaseQuantity($product, $qty, $presentationName);
        }

        $ids = array_keys($requirements);
        $stocks = Stock::where('company_id', $companyId)
            ->whereIn('product_id', $ids)
            ->selectRaw('product_id, SUM(quantity) as total_stock')
            ->groupBy('product_id')
            ->pluck('total_stock', 'product_id');

        $missingLines = [];
        foreach ($requirements as $productId => $requirement) {
            $product = $requirement['product'];
            $required = (float) $requirement['required'];
            $available = (float) $stocks->get($productId, 0) + (float) ($stockAdditions[$productId] ?? 0);
            $missing = max(0, $required - $available);

            if ($missing > 0.00001) {
                $missingLines[] = '- ' . $product->name . ': ' . $this->formatMissing($product, $missing);
            }
        }

        $message = "📋 Plan de producción:\n" . implode("\n", $planLines) . "\n\n";
        if (!$missingLines) {
            $message .= '✅ Con el stock actual alcanza para todo el plan.';
        } else {
            $message .= "❌ Para completar el plan falta:\n" . implode("\n", $missingLines);
        }

        return ['success' => true, 'message' => $message];
    }

    private function formatMissing(Product $product, float $missing): string
    {
        $presentation = $product->presentations()
            ->where('is_purchase_default', true)
            ->first() ?? $product->presentations()->first();

        if ($presentation && (float) $presentation->conversion_factor > 1) {
            $factor = (float) $presentation->conversion_factor;
            $physical = (int) ceil($missing / $factor);
            return "{$physical} {$presentation->name} ({$this->formatNumber($missing)} u)";
        }

        return $this->formatNumber($missing) . ' u';
    }

    private function toBaseQuantity(Product $product, float $quantity, ?string $presentationName): float
    {
        if ($quantity <= 0 || !$presentationName) {
            return max(0, $quantity);
        }

        $resolved = $this->resolvePresentationName($product, $presentationName);
        $presentation = $product->presentations()
            ->where('name', $resolved)
            ->first();

        if (!$presentation) {
            return $quantity;
        }

        return $quantity * (float) $presentation->conversion_factor;
    }

    private function formatProductionUnits(float $units): string
    {
        $whole = (int) floor($units);
        $carros = intdiv($whole, 288);
        $rest = $whole % 288;
        $bandejas = intdiv($rest, 24);
        $sueltas = $rest % 24;
        $parts = [];

        if ($carros) $parts[] = "{$carros} carro" . ($carros === 1 ? '' : 's');
        if ($bandejas) $parts[] = "{$bandejas} bandeja" . ($bandejas === 1 ? '' : 's');
        if ($sueltas) $parts[] = "{$sueltas} u";

        return ($parts ? implode(', ', $parts) : $this->formatNumber($units) . ' u')
            . " ({$this->formatNumber($units)} u)";
    }

    private function meansAllProducts(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return true;
        }

        return in_array($this->normalize($value), [
            'all', 'todo', 'todos', 'todos los productos', 'todos los que tenemos',
            'productos', 'stock total', 'stock completo', 'inventario',
            'inventario completo', 'mercaderia', 'toda la mercaderia',
        ], true);
    }

    private function resolveFinishedProduct(int $companyId, string $input): ?Product
    {
        return $this->resolveProductFromCollection(
            Product::where('company_id', $companyId)->where('type', 'finished_product')->get(),
            $input
        );
    }

    private function resolveProduct(int $companyId, string $input): ?Product
    {
        return $this->resolveProductFromCollection(
            Product::where('company_id', $companyId)->get(),
            $input
        );
    }

    private function resolveProductFromCollection($products, string $input): ?Product
    {
        if ($products->isEmpty()) {
            return null;
        }

        $normalizedInput = $this->normalize($input);
        $queryTokens = $this->tokens($input);
        if (!$queryTokens) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        $tied = false;

        foreach ($products as $product) {
            $normalizedProduct = $this->normalize($product->name);
            $productTokens = $this->tokens($product->name);
            $matches = count(array_intersect($queryTokens, $productTokens));

            if ($normalizedInput === $normalizedProduct) {
                $score = 1000;
            } elseif (str_contains($normalizedProduct, $normalizedInput) || str_contains($normalizedInput, $normalizedProduct)) {
                $score = 500 + ($matches * 20);
            } else {
                if ($matches === 0) {
                    continue;
                }
                $coverage = $matches / count($queryTokens);
                if ($coverage < 0.6) {
                    continue;
                }
                $score = (int) round($coverage * 100) + ($matches * 10) - abs(count($productTokens) - count($queryTokens));
            }

            if ($score > $bestScore) {
                $best = $product;
                $bestScore = $score;
                $tied = false;
            } elseif ($score === $bestScore && $score > 0) {
                $tied = true;
            }
        }

        return $tied ? null : $best;
    }

    private function resolvePresentationName(Product $product, ?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return $input;
        }

        $queryTokens = $this->tokens($input);
        foreach ($product->presentations()->get() as $presentation) {
            $presentationTokens = $this->tokens($presentation->name);
            if (count(array_intersect($queryTokens, $presentationTokens)) === count($queryTokens)) {
                return $presentation->name;
            }
        }

        return $input;
    }

    private function tokens(string $value): array
    {
        $stopWords = ['de', 'del', 'la', 'las', 'el', 'los', 'para', 'y', 'un', 'una', 'unos', 'unas'];
        $parts = preg_split('/\s+/', $this->normalize($value)) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            if ($part === '' || is_numeric($part) || in_array($part, $stopWords, true)) {
                continue;
            }
            $tokens[] = $this->singularize($part);
        }

        return array_values(array_unique($tokens));
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function singularize(string $word): string
    {
        if (mb_strlen($word) > 5 && str_ends_with($word, 'es')) {
            return mb_substr($word, 0, -2);
        }
        if (mb_strlen($word) > 4 && str_ends_with($word, 's')) {
            return mb_substr($word, 0, -1);
        }
        return $word;
    }

    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.00001) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
