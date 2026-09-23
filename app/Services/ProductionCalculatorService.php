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

        // Load presentations for all ingredients in one query
        $presentations = \App\Models\ProductPresentation::whereIn('product_id', $ingredientIds)
            ->where('is_purchase_default', true)
            ->pluck(null, 'product_id')
            ->map(fn($p) => $p);

        foreach ($recipe->items as $item) {
            $required = $item->quantity_base * $multiplier;
            $available = (float) $stocks->get($item->product_id, 0);
            $missing = max(0, $required - $available);
            
            if ($missing > 0) {
                $canProduce = false;
            }

            // Calculate physical presentation quantities
            // NOTE: conversion factors for bacon, lomito, queso fiambre, jamón fiambre
            // are currently UNCONFIRMED (seeder values). Physical representation
            // for those products should be treated as informational only.
            $pres = $presentations->get($item->product_id);
            $factor = $pres ? (float) $pres->conversion_factor : 1.0;
            $presName = $pres ? $pres->name : $item->product->baseUnit->name;
            // Apply ceil() only when the factor > 1 (physical unit is indivisible)
            $requiresPhysicalCeil = $factor > 1;
            $requiredPhysical = $requiresPhysicalCeil ? ceil($required / $factor) : $required;
            $missingPhysical = $requiresPhysicalCeil && $missing > 0 ? ceil($missing / $factor) : $missing;
            
            $items[] = [
                'ingredient' => $item->product,
                'required' => $required,
                'available' => $available,
                'missing' => $missing,
                // Presentation layer — for display only, does NOT affect can_produce logic
                'presentation' => [
                    'name' => $presName,
                    'factor' => $factor,
                    'required_physical' => $requiredPhysical,
                    'missing_physical' => $missingPhysical,
                    'needs_ceil' => $requiresPhysicalCeil,
                    // How many complete vs incomplete units
                    'complete_units' => $requiresPhysicalCeil ? floor($required / $factor) : null,
                    'remainder_units' => $requiresPhysicalCeil ? ($required % $factor) : null,
                ],
            ];
        }

        return [
            'can_produce' => $canProduce,
            'items' => $items,
        ];
    }

    /**
     * Calcula la cantidad máxima de unidades terminadas que puede fabricarse
     * con el stock actual, usando el ingrediente limitante de la receta.
     */
    public function calculateMaxProducible(Product $product, array $stockAdditions = []): array
    {
        $recipe = $product->recipe()->with('items.product')->first();

        if (!$recipe || $recipe->yield_quantity <= 0 || $recipe->items->isEmpty()) {
            throw new Exception("El producto '{$product->name}' no tiene una receta válida configurada.");
        }

        $ingredientIds = $recipe->items->pluck('product_id')->toArray();
        $stocks = Stock::where('company_id', $product->company_id)
            ->whereIn('product_id', $ingredientIds)
            ->selectRaw('product_id, SUM(quantity) as total_stock')
            ->groupBy('product_id')
            ->pluck('total_stock', 'product_id');

        $maxUnits = INF;
        $limitingIngredient = null;

        foreach ($recipe->items as $item) {
            $requiredPerUnit = (float) $item->quantity_base / (float) $recipe->yield_quantity;
            if ($requiredPerUnit <= 0) {
                continue;
            }

            $available = (float) $stocks->get($item->product_id, 0)
                + (float) ($stockAdditions[$item->product_id] ?? 0);
            $possible = floor($available / $requiredPerUnit);

            if ($possible < $maxUnits) {
                $maxUnits = $possible;
                $limitingIngredient = $item->product;
            }
        }

        if ($maxUnits === INF) {
            $maxUnits = 0;
        }

        return [
            'max_units' => (int) $maxUnits,
            'limiting_ingredient' => $limitingIngredient,
        ];
    }

}
