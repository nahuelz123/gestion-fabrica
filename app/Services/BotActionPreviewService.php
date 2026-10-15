<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\User;

class BotActionPreviewService
{
    public function preview(User $user, array $action): ?string
    {
        $name = $action['name'] ?? '';
        $args = is_array($action['arguments'] ?? null) ? $action['arguments'] : [];

        if (in_array($name, ['add_stock', 'register_stock', 'adjust_stock'], true)) {
            return $this->previewStockAddition($user->company_id, $args);
        }

        if ($name === 'remove_stock') {
            return $this->previewSingleStockChange($user->company_id, $args, 'restar');
        }

        if ($name === 'set_stock') {
            return $this->previewSingleStockChange($user->company_id, $args, 'establecer');
        }

        if ($name === 'register_production') {
            $product = $args['product_name'] ?? 'el producto indicado';
            $carros = (float) ($args['carros'] ?? 0);
            $bandejas = (float) ($args['bandejas'] ?? 0);
            $quantity = ($carros > 0 || $bandejas > 0)
                ? ProductionOrderService::toHamburguesas($carros, $bandejas)
                : (float) ($args['quantity'] ?? 0);

            $parts = [];
            if ($carros > 0) $parts[] = $this->number($carros) . ' ' . ($carros == 1 ? 'carro' : 'carros');
            if ($bandejas > 0) $parts[] = $this->number($bandejas) . ' ' . ($bandejas == 1 ? 'bandeja' : 'bandejas');
            $display = $parts
                ? implode(' + ', $parts) . ' = ' . $this->number($quantity) . ' unidades'
                : $this->number($quantity) . ' unidades';

            $lines = ["Voy a registrar producción de {$product}: {$display}."];

            $actual = is_array($args['actual_consumptions'] ?? null)
                ? $args['actual_consumptions']
                : [];

            if ($actual) {
                $lines[] = 'Consumos reales informados:';
                foreach (array_slice($actual, 0, 30) as $item) {
                    if (!is_array($item) || empty($item['product_name']) || !isset($item['quantity'])) continue;

                    $quantity = (float) $item['quantity'];
                    $presentation = trim((string) ($item['presentation_name'] ?? ''));
                    $label = $presentation !== '' ? $presentation : 'u';
                    $lines[] = '- ' . (string) $item['product_name'] . ': ' . $this->number($quantity) . ' ' . $label;
                }
                $lines[] = 'Los demás insumos se descontarán según la receta.';
            } else {
                $lines[] = 'Se descontarán los insumos según la receta.';
            }

            $lines[] = '¿Confirmás?';
            return implode("\n", $lines);
        }

        if ($name === 'update_product') {
            $product = (string) ($args['product_name'] ?? $args['name'] ?? 'el producto');
            $changes = [];
            foreach (['new_name' => 'nombre', 'min_stock' => 'mínimo de stock', 'shelf_life_days' => 'vida útil (días)', 'presentation_name' => 'presentación'] as $field => $label) {
                if (isset($args[$field]) && $args[$field] !== '') {
                    $changes[] = '- ' . $label . ': ' . (string) $args[$field];
                }
            }
            if (!$changes) return 'No identifico qué dato querés cambiar. Decime el campo y el valor.';
            return "Voy a actualizar {$product}:\n" . implode("\n", $changes) . "\n¿Confirmás?";
        }

        if (in_array($name, ['create_recipe', 'update_recipe'], true)) {
            $product = (string) ($args['product_name'] ?? 'el producto final');
            $yield = (float) ($args['yield_quantity'] ?? 0);
            $items = is_array($args['items'] ?? null) ? $args['items'] : [];
            if ($yield <= 0 || !$items) {
                return 'Para editar la receta necesito rendimiento e ingredientes con sus cantidades.';
            }
            $lines = ["Voy a guardar la receta de {$product} para un rendimiento de {$yield} unidades:"];
            foreach (array_slice($items, 0, 30) as $item) {
                if (!is_array($item)) continue;
                $lines[] = '- ' . (string) ($item['product_name'] ?? 'Insumo sin identificar') . ': ' .
                    (string) ($item['quantity'] ?? '?') . ' ' . (string) ($item['presentation_name'] ?? 'u');
            }
            $lines[] = '¿Confirmás?';
            return implode("\n", $lines);
        }

        if ($name === 'create_product') {
            $product = $args['name'] ?? 'el producto';
            $presentation = $args['presentation'] ?? null;
            $extra = $presentation ? " con presentación '{$presentation}'" : '';
            return "Voy a crear {$product}{$extra}. ¿Confirmás?";
        }

        return null;
    }

    private function previewStockAddition(int $companyId, array $args): string
    {
        $items = is_array($args['items'] ?? null) ? $args['items'] : [];

        if ($items) {
            $lines = ['Voy a sumar al stock:'];
            $defaultQuantity = (float) ($args['quantity'] ?? 0);

            foreach (array_slice($items, 0, 30) as $item) {
                if (!is_array($item) || empty($item['product_name'])) continue;
                $quantity = (float) ($item['quantity'] ?? $defaultQuantity);
                $product = $this->findProduct($companyId, (string) $item['product_name']);
                $productName = $product?->name ?? (string) $item['product_name'];
                [$baseQuantity, $presentationName] = $this->convert($product, $quantity, $item['presentation_name'] ?? ($args['presentation_name'] ?? null));

                $display = $presentationName
                    ? $this->number($quantity) . ' ' . $this->displayPresentation($presentationName, $quantity) . ' = ' . $this->number($baseQuantity) . ' u'
                    : $this->number($quantity) . ' u';
                $lines[] = "- {$productName}: {$display}";
            }

            $lines[] = '¿Confirmás?';
            return implode("\n", $lines);
        }

        return $this->previewSingleStockChange($companyId, $args, 'sumar');
    }

    private function previewSingleStockChange(int $companyId, array $args, string $verb): string
    {
        $name = $args['product_name'] ?? null;
        $quantity = (float) ($args['quantity'] ?? 0);
        if (!$name || $quantity <= 0) {
            return 'La operación necesita producto y cantidad antes de poder confirmarse.';
        }

        $product = $this->findProduct($companyId, (string) $name);
        $productName = $product?->name ?? (string) $name;
        [$baseQuantity, $presentationName] = $this->convert($product, $quantity, $args['presentation_name'] ?? null);

        if ($verb === 'establecer') {
            return "Voy a establecer el stock de {$productName} en " . $this->number($baseQuantity) . " u. ¿Confirmás?";
        }

        $display = $presentationName
            ? $this->number($quantity) . " {$presentationName} = " . $this->number($baseQuantity) . ' u'
            : $this->number($quantity) . ' u';

        return "Voy a {$verb} {$display} de {$productName}. ¿Confirmás?";
    }

    private function findProduct(int $companyId, string $input): ?Product
    {
        $exact = Product::where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($input))])
            ->first();
        if ($exact) return $exact;

        $alias = ProductAlias::where('company_id', $companyId)
            ->whereRaw('LOWER(alias) = ?', [mb_strtolower(trim($input))])
            ->with('product')
            ->first();

        return $alias?->product;
    }

    private function convert(?Product $product, float $quantity, ?string $presentationName): array
    {
        if (!$product || !$presentationName || trim($presentationName) === '') {
            return [$quantity, null];
        }

        $needle = $this->presentationToken($presentationName);
        $presentation = $product->presentations()
            ->get()
            ->first(function ($candidate) use ($needle) {
                return $this->presentationToken((string)$candidate->name) === $needle;
            });

        if (!$presentation) {
            return [$quantity, $presentationName];
        }

        return [
            $quantity * (float) $presentation->conversion_factor,
            $presentation->name,
        ];
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
        $value=mb_strtolower(trim($value));
        if (preg_match('/\\b(caja|cajas)\\b/u',$value)) return 'caja';
        if (preg_match('/\\b(barra|barras)\\b/u',$value)) return 'barra';
        if (preg_match('/\\b(pieza|piezas)\\b/u',$value)) return 'pieza';
        if (preg_match('/\\b(paquete|paquetes)\\b/u',$value)) return 'paquete';
        if (preg_match('/\\b(bolsa|bolsas)\\b/u',$value)) return 'bolsa';
        if (preg_match('/\\b(feta|fetas)\\b/u',$value)) return 'feta';
        if (preg_match('/\\b(unidad|unidades|u)\\b/u',$value)) return 'unidad';
        return $value;
    }

    private function number(float $value): string
    {
        return abs($value - round($value)) < 0.00001
            ? (string) (int) round($value)
            : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
