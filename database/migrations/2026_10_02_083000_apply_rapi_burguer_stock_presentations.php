<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $specs = [
            [
                'names' => ['pan', 'caja de pan'],
                'presentation' => 'caja de 36',
                'factor' => 36,
            ],
            [
                'names' => ['medallon', 'medallon de carne', 'medallon carne', 'paty', 'patty'],
                'presentation' => 'caja de 60',
                'factor' => 60,
            ],
            [
                'names' => ['fiambre cheddar', 'cheddar', 'chedar', 'queso cheddar', 'queso chedar'],
                'presentation' => 'barra de 200',
                'factor' => 200,
            ],
            [
                'names' => ['queso', 'queso fiambre', 'fiambre queso'],
                'presentation' => 'barra de 200',
                'factor' => 200,
            ],
            [
                'names' => ['jamon', 'jamon fiambre', 'fiambre jamon'],
                'presentation' => 'barra de 240',
                'factor' => 240,
            ],
        ];

        $products = DB::table('products')
            ->where('type', 'raw_material')
            ->select(['id', 'name'])
            ->get();

        foreach ($products as $product) {
            $labels = [(string) $product->name];

            $aliases = DB::table('product_aliases')
                ->where('product_id', $product->id)
                ->pluck('alias')
                ->all();

            foreach ($aliases as $alias) {
                $labels[] = (string) $alias;
            }

            $normalizedLabels = array_map(fn (string $label) => $this->normalize($label), $labels);

            foreach ($specs as $spec) {
                $normalizedNames = array_map(fn (string $name) => $this->normalize($name), $spec['names']);

                if (!array_intersect($normalizedLabels, $normalizedNames)) {
                    continue;
                }

                DB::table('product_presentations')
                    ->where('product_id', $product->id)
                    ->update(['is_purchase_default' => false, 'updated_at' => now()]);

                $existing = DB::table('product_presentations')
                    ->where('product_id', $product->id)
                    ->where('name', $spec['presentation'])
                    ->first();

                if ($existing) {
                    DB::table('product_presentations')
                        ->where('id', $existing->id)
                        ->update([
                            'conversion_factor' => $spec['factor'],
                            'is_purchase_default' => true,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('product_presentations')->insert([
                        'product_id' => $product->id,
                        'name' => $spec['presentation'],
                        'barcode' => null,
                        'conversion_factor' => $spec['factor'],
                        'is_purchase_default' => true,
                        'is_sale_default' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('products')
                    ->where('id', $product->id)
                    ->update([
                        'presentation' => $spec['presentation'],
                        'updated_at' => now(),
                    ]);

                break;
            }
        }
    }

    public function down(): void
    {
        // No tocamos stock ni eliminamos presentaciones porque pueden haber quedado
        // referenciadas por movimientos posteriores. Esta migración sólo corrige
        // metadatos de conversión para futuros ingresos y cálculos.
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
};
