<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const STANDARD_YIELD = 288.0; // 1 carro

    public function up(): void
    {
        $companies = DB::table('products')
            ->where('type', 'finished_product')
            ->distinct()
            ->pluck('company_id');

        foreach ($companies as $companyId) {
            $finished = DB::table('products')
                ->where('company_id', $companyId)
                ->where('type', 'finished_product')
                ->get(['id', 'name']);

            foreach ($finished as $product) {
                $name = $this->normalize((string) $product->name);

                if (str_contains($name, 'hamburguesa bacon')) {
                    $this->normalizeRecipe($product->id, [
                        'bacon' => 3.5, // 7 piezas cada 2 carros, estimación conservadora
                    ], $companyId);
                } elseif (str_contains($name, 'hamburguesa lomito')) {
                    $this->normalizeRecipe($product->id, [
                        'lomito' => 2.5, // 5 piezas cada 2 carros
                    ], $companyId);
                } elseif (str_contains($name, 'hamburguesa jamon y queso')) {
                    $this->normalizeRecipe($product->id, [
                        'queso' => 250.0, // 2,5 barras de 200 cada 2 carros
                        'jamon' => 280.0, // 3,5 barras de 240 cada 3 carros
                    ], $companyId);
                }
            }
        }
    }

    public function down(): void
    {
        // Estos valores representan la operación real informada por Rapi Burguer.
        // No se revierte automáticamente para no restaurar recetas demo incorrectas.
    }

    private function normalizeRecipe(int $finishedProductId, array $overrides, int $companyId): void
    {
        $recipe = DB::table('recipes')
            ->where('company_id', $companyId)
            ->where('product_id', $finishedProductId)
            ->first();

        if (!$recipe || (float) $recipe->yield_quantity <= 0) {
            return;
        }

        $oldYield = (float) $recipe->yield_quantity;
        $multiplier = self::STANDARD_YIELD / $oldYield;

        // Preserve the ratios of every existing ingredient while moving the
        // recipe to a one-cart standard. Then replace only the ingredients for
        // which the factory gave us a real-world estimate.
        $items = DB::table('recipe_items')
            ->where('recipe_id', $recipe->id)
            ->get(['id', 'product_id', 'quantity_base']);

        foreach ($items as $item) {
            DB::table('recipe_items')
                ->where('id', $item->id)
                ->update([
                    'quantity_base' => round((float) $item->quantity_base * $multiplier, 2),
                    'updated_at' => now(),
                ]);
        }

        DB::table('recipes')
            ->where('id', $recipe->id)
            ->update([
                'yield_quantity' => self::STANDARD_YIELD,
                'updated_at' => now(),
            ]);

        foreach ($overrides as $ingredientKey => $quantity) {
            $ingredientId = $this->findIngredientId($companyId, $ingredientKey);
            if (!$ingredientId) continue;

            DB::table('recipe_items')
                ->where('recipe_id', $recipe->id)
                ->where('product_id', $ingredientId)
                ->update([
                    'quantity_base' => $quantity,
                    'updated_at' => now(),
                ]);
        }
    }

    private function findIngredientId(int $companyId, string $key): ?int
    {
        $products = DB::table('products')
            ->where('company_id', $companyId)
            ->where('type', 'raw_material')
            ->get(['id', 'name']);

        foreach ($products as $product) {
            $name = $this->normalize((string) $product->name);

            $matches = match ($key) {
                'bacon' => $name === 'bacon',
                'lomito' => $name === 'lomito',
                'queso' => in_array($name, ['queso', 'queso fiambre', 'fiambre queso'], true),
                'jamon' => in_array($name, ['jamon', 'jamon fiambre', 'fiambre jamon'], true),
                default => false,
            };

            if ($matches) return (int) $product->id;
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
};
