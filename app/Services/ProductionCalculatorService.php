<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPresentation;
use App\Models\Stock;
use Exception;

class ProductionCalculatorService
{
    public function calculateRequirements(Product $product, float $targetQuantity): array
    {
        if (!is_finite($targetQuantity) || $targetQuantity <= 0) throw new Exception('La cantidad a producir debe ser mayor a 0.');
        $recipe = $product->recipe()->with('items.product.baseUnit')->first();
        if (!$recipe) throw new Exception("El producto '{$product->name}' no tiene una receta configurada.");
        if ($recipe->yield_quantity <= 0) throw new Exception('El rendimiento de la receta debe ser mayor a 0.');
        $multiplier = $targetQuantity / $recipe->yield_quantity;
        $ingredientIds = $recipe->items->pluck('product_id')->all();
        $stocks = Stock::where('company_id',$product->company_id)->whereIn('product_id',$ingredientIds)
            ->selectRaw('product_id, SUM(quantity) as total_stock')->groupBy('product_id')->pluck('total_stock','product_id');
        $presentations = ProductPresentation::whereIn('product_id',$ingredientIds)->where('is_purchase_default',true)->get()->keyBy('product_id');
        $items = []; $canProduce = true;
        foreach ($recipe->items as $item) {
            if ($item->product->company_id !== $product->company_id) throw new Exception('La receta contiene un insumo de otra empresa.');
            $required = (float)$item->quantity_base * $multiplier;
            $available = (float)$stocks->get($item->product_id,0);
            $missing = max(0,$required-$available);
            if ($missing > 0) $canProduce = false;
            $pres = $presentations->get($item->product_id);
            $factor = $pres ? (float)$pres->conversion_factor : 1.0;
            $presName = $pres ? $pres->name : ($item->product->baseUnit->name ?? 'unidad');
            $requiresPhysicalCeil = $factor > 1;
            $items[] = [
                'ingredient'=>$item->product,'required'=>$required,'available'=>$available,'missing'=>$missing,
                'presentation'=>[
                    'name'=>$presName,'factor'=>$factor,
                    'required_physical'=>$requiresPhysicalCeil ? ceil($required/$factor) : $required,
                    'missing_physical'=>$requiresPhysicalCeil && $missing>0 ? ceil($missing/$factor) : $missing,
                    'needs_ceil'=>$requiresPhysicalCeil,'complete_units'=>$requiresPhysicalCeil ? floor($required/$factor) : null,
                    'remainder_units'=>$requiresPhysicalCeil ? fmod($required,$factor) : null,
                ],
            ];
        }
        return ['can_produce'=>$canProduce,'items'=>$items];
    }

    public function calculateMaxProducible(Product $product, array $stockAdditions = []): array
    {
        $recipe = $product->recipe()->with('items.product')->first();
        if (!$recipe || $recipe->yield_quantity <= 0 || $recipe->items->isEmpty()) throw new Exception("El producto '{$product->name}' no tiene una receta válida configurada.");
        $ingredientIds = $recipe->items->pluck('product_id')->all();
        $stocks = Stock::where('company_id',$product->company_id)->whereIn('product_id',$ingredientIds)->selectRaw('product_id, SUM(quantity) as total_stock')->groupBy('product_id')->pluck('total_stock','product_id');
        $maxUnits = INF; $limitingIngredient = null;
        foreach ($recipe->items as $item) {
            if ($item->product->company_id !== $product->company_id) throw new Exception('La receta contiene un insumo de otra empresa.');
            $requiredPerUnit = (float)$item->quantity_base/(float)$recipe->yield_quantity;
            if ($requiredPerUnit <= 0) continue;
            $available = (float)$stocks->get($item->product_id,0)+(float)($stockAdditions[$item->product_id] ?? 0);
            $possible = floor(max(0,$available)/$requiredPerUnit);
            if ($possible < $maxUnits) { $maxUnits = $possible; $limitingIngredient = $item->product; }
        }
        if ($maxUnits === INF) $maxUnits = 0;
        return ['max_units'=>(int)$maxUnits,'limiting_ingredient'=>$limitingIngredient];
    }
}
