<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $groups = [
            [
                'names' => ['chedar', 'cheddar', 'fiambre cheddar', 'fiambre chedar', 'queso cheddar', 'queso chedar'],
                'aliases' => ['chedar', 'cheddar', 'queso cheddar', 'queso chedar', 'fiambre cheddar', 'fiambre chedar'],
            ],
            [
                'names' => ['medallon', 'medallon de carne', 'medallon carne', 'paty', 'patty'],
                'aliases' => ['medallon', 'medallón', 'medallon de carne', 'medallón de carne', 'medallon carne', 'paty', 'patty'],
            ],
            [
                'names' => ['pan', 'caja de pan'],
                'aliases' => ['pan', 'caja de pan'],
            ],
            [
                'names' => ['queso', 'queso fiambre', 'fiambre queso'],
                'aliases' => ['queso', 'queso fiambre', 'fiambre queso'],
            ],
            [
                'names' => ['jamon', 'jamon fiambre', 'fiambre jamon'],
                'aliases' => ['jamon', 'jamón', 'jamon fiambre', 'jamón fiambre', 'fiambre jamon', 'fiambre jamón'],
            ],
            [
                'names' => ['bacon'],
                'aliases' => ['bacon', 'pieza de bacon'],
            ],
            [
                'names' => ['lomito'],
                'aliases' => ['lomito', 'pieza de lomito'],
            ],
            [
                'names' => ['papel manteca'],
                'aliases' => ['papel manteca', 'papel'],
            ],
            [
                'names' => ['bolsitas', 'bolsitas para hamburguesas', 'bolsa', 'bolsas'],
                'aliases' => ['bolsitas', 'bolsitas para hamburguesas', 'bolsa para hamburguesa', 'bolsas para hamburguesas'],
            ],
        ];

        $companies = DB::table('products')
            ->where('type', 'raw_material')
            ->distinct()
            ->pluck('company_id');

        foreach ($companies as $companyId) {
            $products = DB::table('products')
                ->where('company_id', $companyId)
                ->where('type', 'raw_material')
                ->orderBy('id')
                ->get(['id', 'name']);

            foreach ($groups as $group) {
                $candidateNames = array_map(fn ($name) => $this->normalize($name), $group['names']);

                $target = $products->first(function ($product) use ($candidateNames) {
                    return in_array($this->normalize((string) $product->name), $candidateNames, true);
                });

                if (!$target) continue;

                foreach ($group['aliases'] as $alias) {
                    $existing = DB::table('product_aliases')
                        ->where('company_id', $companyId)
                        ->whereRaw('LOWER(alias) = ?', [mb_strtolower($alias)])
                        ->first();

                    if ($existing) {
                        DB::table('product_aliases')
                            ->where('id', $existing->id)
                            ->update([
                                'product_id' => $target->id,
                                'alias' => $alias,
                                'updated_at' => now(),
                            ]);
                    } else {
                        DB::table('product_aliases')->insert([
                            'company_id' => $companyId,
                            'product_id' => $target->id,
                            'alias' => $alias,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Los alias son metadatos de reconocimiento del lenguaje de la fábrica.
        // No se eliminan para evitar romper conversaciones o integraciones existentes.
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
};
