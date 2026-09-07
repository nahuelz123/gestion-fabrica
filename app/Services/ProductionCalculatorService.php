<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
use Exception;

class ProductionCalculatorService
{
    /**
     * Calcula los insumos necesarios para fabricar $targetQuantity de un $product.
     * Verifica el stock actual y determina si hay cantidad suficiente para producir.
     * 
     * @return array{can_produce: bool, items: array}
     */
    public function calculateRequirements(Product $product, float $targetQuantity): array
    {
        $recipe = $product->recipe()->with('items.product.baseUnit')->first();
        
        if (!$recipe) {
            throw new Exception("El producto '{$product->name}' no tiene una receta configurada.");
        }

        if ($recipe->yield_quantity <= 0) {
            throw new Exception("El rendimiento de la receta debe ser mayor a 0.");
        }

        $multiplier = $targetQuantity / $recipe->yield_quantity;
        $items = [];
        $canProduce = true;

        // Obtain consolidated stock for all required ingredients across all warehouses of the company
        $ingredientIds = $recipe->items->pluck('product_id')->toArray();
        $stocks = Stock::where('company_id', $product->company_id)
            ->whereIn('product_id', $ingredientIds)
            ->selectRaw('product_id, SUM(quantity) as total_stock')
            ->groupBy('product_id')
            ->pluck('total_stock', 'product_id');

        foreach ($recipe->items as $item) {
            $required = $item->quantity_base * $multiplier;
            $available = (float) $stocks->get($item->product_id, 0);
            $missing = max(0, $required - $available);
            
            if ($missing > 0) {
                $canProduce = false;
            }
            
            $items[] = [
                'ingredient' => $item->product,
                'required' => $required,
                'available' => $available,
                'missing' => $missing,
            ];
        }

        return [
            'can_produce' => $canProduce,
            'items' => $items,
        ];
    }
}
