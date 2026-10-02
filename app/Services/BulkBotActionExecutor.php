<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\MovementType;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BulkBotActionExecutor extends FlexibleBotActionExecutor
{
    public function __construct(
        ProductService $productService,
        private StockService $stockService,
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

        if (in_array($name, ['add_stock', 'register_stock', 'adjust_stock'], true)
            && !empty($args['items'])
            && is_array($args['items'])) {
            return $this->executeBulkAddStock($user, $args);
        }

        return parent::execute($user, $chatId, $action, $context);
    }

    private function executeBulkAddStock(User $user, array $args): array
    {
        $companyId = $user->company_id;
        $warehouse = Warehouse::where('company_id', $companyId)->first();

        if (!$warehouse) {
            return ['success' => false, 'message' => 'No encontré un depósito configurado en tu empresa.'];
        }

        $defaultQuantity = (float) ($args['quantity'] ?? 0);
        $prepared = [];

        foreach ($args['items'] as $item) {
            if (!is_array($item) || empty($item['product_name'])) {
                continue;
            }

            $product = $this->resolveProductForBulk($companyId, (string) $item['product_name']);
            if (!$product) {
                return [
                    'success' => false,
                    'message' => "No pude identificar el producto '{$item['product_name']}'. No modifiqué ningún stock.",
                ];
            }

            $quantity = (float) ($item['quantity'] ?? $defaultQuantity);
            if ($quantity <= 0) {
                return [
                    'success' => false,
                    'message' => "Me falta una cantidad válida para {$product->name}. No modifiqué ningún stock.",
                ];
            }

            $presentation = $this->resolvePresentationForBulk(
                $product,
                $item['presentation_name'] ?? ($args['presentation_name'] ?? null)
            );

            $baseQuantity = $quantity;
            $presentationId = null;
            $presentationQuantity = null;

            if ($presentation) {
                $baseQuantity = $quantity * (float) $presentation->conversion_factor;
                $presentationId = $presentation->id;
                $presentationQuantity = $quantity;
            }

            $prepared[] = [
                'product' => $product,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'presentation' => $presentation,
                'presentation_id' => $presentationId,
                'presentation_quantity' => $presentationQuantity,
            ];
        }

        if (empty($prepared)) {
            return ['success' => false, 'message' => 'No encontré productos válidos para actualizar.'];
        }

        try {
            DB::transaction(function () use ($prepared, $companyId, $warehouse, $user) {
                foreach ($prepared as $entry) {
                    $this->stockService->registerMovement([
                        'company_id' => $companyId,
                        'product_id' => $entry['product']->id,
                        'warehouse_id' => $warehouse->id,
                        'type' => MovementType::AdjustmentIn,
                        'quantity_base' => $entry['base_quantity'],
                        'presentation_id' => $entry['presentation_id'],
                        'presentation_quantity' => $entry['presentation_quantity'],
                        'user_id' => $user->id,
                        'channel' => Channel::Telegram,
                        'reason' => 'Ingreso masivo por bot',
                    ]);
                }
            });
        } catch (Throwable $e) {
            report($e);
            return [
                'success' => false,
                'message' => 'No pude completar el ingreso masivo. No se aplicó ningún cambio.',
            ];
        }

        $productIds = collect($prepared)->pluck('product.id')->all();
        $stocks = Stock::where('company_id', $companyId)
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, SUM(quantity) as total_stock')
            ->groupBy('product_id')
            ->pluck('total_stock', 'product_id');

        $lines = ['✅ Stock actualizado:'];
        foreach ($prepared as $entry) {
            $current = (float) $stocks->get($entry['product']->id, 0);
            $addedLabel = $entry['presentation']
                ? $this->formatNumber($entry['quantity']) . ' ' . $this->displayPresentation((string)$entry['presentation']->name, (float)$entry['quantity'])
                    . ' (' . $this->formatNumber((float)$entry['base_quantity']) . ' u)'
                : $this->formatNumber($entry['quantity']) . ' u';

            $lines[] = '- ' . $entry['product']->name
                . ': +' . $addedLabel
                . ' → ' . $this->formatNumber($current) . ' u';
        }

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    private function resolveProductForBulk(int $companyId, string $input): ?Product
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // 1) Coincidencia exacta por nombre: usa índice company+name.
        $exact = Product::where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($input)])
            ->first();
        if ($exact) {
            return $exact;
        }

        // 2) Alias exacto: evita fuzzy matching costoso y permite lenguaje de fábrica.
        $alias = ProductAlias::where('company_id', $companyId)
            ->whereRaw('LOWER(alias) = ?', [mb_strtolower($input)])
            ->with('product')
            ->first();
        if ($alias?->product) {
            return $alias->product;
        }

        // 3) Preselección acotada por tokens. Nunca cargamos miles de productos en memoria.
        $needleTokens = $this->tokens($input);
        if (!$needleTokens) {
            return null;
        }

        $candidates = Product::where('company_id', $companyId)
            ->where(function ($query) use ($needleTokens) {
                foreach (array_slice($needleTokens, 0, 4) as $token) {
                    $query->orWhere('name', 'like', '%' . $token . '%')
                        ->orWhereHas('aliases', fn ($aliasQuery) => $aliasQuery->where('alias', 'like', '%' . $token . '%'));
                }
            })
            ->with('aliases:id,product_id,alias')
            ->limit(60)
            ->get();

        $best = null;
        $bestScore = 0;
        $tied = false;

        foreach ($candidates as $product) {
            $labels = collect([$product->name])
                ->merge($product->aliases->pluck('alias'));

            foreach ($labels as $label) {
                $tokens = $this->tokens((string) $label);
                $matches = count(array_intersect($needleTokens, $tokens));
                if ($matches === 0) {
                    continue;
                }

                $coverage = $matches / max(1, count($needleTokens));
                if ($coverage < 0.6) {
                    continue;
                }

                $score = (int) round($coverage * 100) - abs(count($tokens) - count($needleTokens));
                if ($score > $bestScore) {
                    $best = $product;
                    $bestScore = $score;
                    $tied = false;
                } elseif ($score === $bestScore && $best && $best->id !== $product->id) {
                    $tied = true;
                }
            }
        }

        return $tied ? null : $best;
    }

    private function resolvePresentationForBulk(Product $product, ?string $input)
    {
        if (!$input || trim($input) === '') {
            return null;
        }

        $needle = $this->presentationToken($input);

        foreach ($product->presentations()->get() as $presentation) {
            if ($this->presentationToken((string)$presentation->name) === $needle) {
                return $presentation;
            }
        }

        return null;
    }

    private function displayPresentation(string $value,float $quantity): string
    {
        $token=$this->presentationToken($value);
        $plural=abs($quantity-1.0) > 0.00001;

        return match($token) {
            'caja' => $plural ? 'cajas' : 'caja',
            'barra' => $plural ? 'barras' : 'barra',
            'pieza' => $plural ? 'piezas' : 'pieza',
            'paquete' => $plural ? 'paquetes' : 'paquete',
            'bolsa' => $plural ? 'bolsas' : 'bolsa',
            'feta' => $plural ? 'fetas' : 'feta',
            'unidad' => $plural ? 'unidades' : 'unidad',
            default => $value,
        };
    }

    private function presentationToken(string $value): string
    {
        $normalized=$this->normalize($value);
        $tokens=preg_split('/\\s+/',$normalized) ?: [];

        foreach ($tokens as $token) {
            if (in_array($token,['caja','cajas'],true)) return 'caja';
            if (in_array($token,['barra','barras'],true)) return 'barra';
            if (in_array($token,['pieza','piezas'],true)) return 'pieza';
            if (in_array($token,['paquete','paquetes'],true)) return 'paquete';
            if (in_array($token,['bolsa','bolsas'],true)) return 'bolsa';
            if (in_array($token,['feta','fetas'],true)) return 'feta';
            if (in_array($token,['unidad','unidades','u'],true)) return 'unidad';
        }

        return $normalized;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
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

            if (mb_strlen($part) > 5 && str_ends_with($part, 'es')) {
                $part = mb_substr($part, 0, -2);
            } elseif (mb_strlen($part) > 4 && str_ends_with($part, 's')) {
                $part = mb_substr($part, 0, -1);
            }

            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    private function formatNumber(float $value): string
    {
        if (abs($value - round($value)) < 0.00001) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
