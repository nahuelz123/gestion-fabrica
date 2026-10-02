<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $specs = [
            ['names' => ['pan', 'caja de pan'], 'presentation' => 'caja de 36', 'factor' => 36],
            ['names' => ['medallon', 'medallon de carne', 'medallon carne', 'medallon de pollo', 'paty', 'patty'], 'presentation' => 'caja de 60', 'factor' => 60],
            ['names' => ['fiambre cheddar', 'cheddar', 'chedar', 'queso cheddar', 'queso chedar'], 'presentation' => 'barra de 200', 'factor' => 200],
            ['names' => ['queso', 'queso fiambre', 'fiambre queso'], 'presentation' => 'barra de 200', 'factor' => 200],
            ['names' => ['jamon', 'jamon fiambre', 'fiambre jamon'], 'presentation' => 'barra de 240', 'factor' => 240],
            ['names' => ['bacon'], 'presentation' => 'pieza', 'factor' => 1],
            ['names' => ['lomito'], 'presentation' => 'pieza', 'factor' => 1],
        ];

        $products = DB::table('products')
            ->where('type', 'raw_material')
            ->select(['id', 'name'])
            ->get();

        foreach ($products as $product) {
            $labels = [(string) $product->name];
            foreach (DB::table('product_aliases')->where('product_id', $product->id)->pluck('alias')->all() as $alias) {
                $labels[] = (string) $alias;
            }

            $labels = array_map(fn (string $value) => $this->normalize($value), $labels);

            foreach ($specs as $spec) {
                $names = array_map(fn (string $value) => $this->normalize($value), $spec['names']);
                if (!array_intersect($labels, $names)) continue;

                DB::table('product_presentations')
                    ->where('product_id', $product->id)
                    ->update(['is_purchase_default' => false, 'updated_at' => now()]);

                $presentation = DB::table('product_presentations')
                    ->where('product_id', $product->id)
                    ->where('name', $spec['presentation'])
                    ->first();

                if ($presentation) {
                    DB::table('product_presentations')->where('id', $presentation->id)->update([
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

                DB::table('products')->where('id', $product->id)->update([
                    'presentation' => $spec['presentation'],
                    'updated_at' => now(),
                ]);

                break;
            }
        }
    }

    public function down(): void
    {
        // No revertimos metadatos de presentación que ya pueden estar referenciados
        // por movimientos de stock.
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
};
