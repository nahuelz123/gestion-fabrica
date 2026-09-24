<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
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
        $args = $action['arguments'] ?? [];

        // "Dame un resumen del stock", "stock de todos", etc.
        // Gemini suele emitir get_stock sin product_name en estos casos.
        if ($name === 'get_stock' && $this->meansAllProducts($args['product_name'] ?? null)) {
            return $this->executeGetAllStock($user->company_id);
        }

        // Canonicaliza nombres naturales/plurales contra el catálogo real.
        // Ej.: "medallones de carne" -> "Medallón de carne".
        if (!empty($args['product_name'])) {
            $product = $this->resolveProduct($user->company_id, (string) $args['product_name']);
            if ($product) {
                $args['product_name'] = $product->name;
                $args['presentation_name'] = $this->resolvePresentationName(
                    $product,
                    $args['presentation_name'] ?? null
                );
            }
        }

        // También canonicaliza listas de productos y escenarios hipotéticos.
        foreach (['items', 'actual_consumptions', 'hypothetical_stock_additions'] as $listKey) {
            if (empty($args[$listKey]) || !is_array($args[$listKey])) {
                continue;
            }

            foreach ($args[$listKey] as $index => $item) {
                if (empty($item['product_name'])) {
                    continue;
                }

                $product = $this->resolveProduct($user->company_id, (string) $item['product_name']);
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

        // Un ingreso expresado en cajas/barras/paquetes debe pasar por add_stock,
        // que ya convierte la presentación física a unidades base.
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

        $lines = ['📦 Stock actual:'];

        foreach ($products as $product) {
            $qty = (float) $product->stocks->sum('quantity');
            $baseUnit = $product->baseUnit->abbreviation ?? 'u';
            $line = "- {$product->name}: {$this->formatNumber($qty)} {$baseUnit}";

            $presentation = $product->presentations
                ->firstWhere('is_purchase_default', true)
                ?? $product->presentations->first();

            if ($presentation && (float) $presentation->conversion_factor > 1) {
                $physical = $qty / (float) $presentation->conversion_factor;
                $line .= " ({$this->formatNumber($physical)} {$presentation->name})";
            }

            $lines[] = $line;
        }

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    private function meansAllProducts(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return true;
        }

        $normalized = $this->normalize($value);

        return in_array($normalized, [
            'todos', 'todo', 'todos los productos', 'productos', 'stock total',
            'inventario', 'inventario completo', 'todos los que tenemos',
        ], true);
    }

    private function resolveProduct(int $companyId, string $input): ?Product
    {
        $products = Product::where('company_id', $companyId)->get();
        if ($products->isEmpty()) {
            return null;
        }

        $queryTokens = $this->tokens($input);
        if (empty($queryTokens)) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        $tied = false;

        foreach ($products as $product) {
            $productTokens = $this->tokens($product->name);
            $matches = count(array_intersect($queryTokens, $productTokens));

            // Exigimos que todas las palabras relevantes indicadas por el usuario
            // aparezcan en el nombre del producto para evitar falsos positivos.
            if ($matches !== count($queryTokens)) {
                continue;
            }

            $score = ($matches * 10) - abs(count($productTokens) - count($queryTokens));
            if ($this->normalize($input) === $this->normalize($product->name)) {
                $score += 100;
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
        if (empty($queryTokens)) {
            return $input;
        }

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
        $stopWords = ['de', 'del', 'la', 'las', 'el', 'los', 'para', 'y', 'un', 'una'];
        $parts = preg_split('/\s+/', $this->normalize($value)) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            if ($part === '' || in_array($part, $stopWords, true) || is_numeric($part)) {
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
