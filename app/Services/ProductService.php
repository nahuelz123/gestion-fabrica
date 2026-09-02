<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPresentation;

class ProductService
{
    /**
     * Convert a quantity in a given presentation to base units.
     *
     * Example: resolveToBaseUnit($medallon, $cajaX50, 4) => 200
     * (4 cajas × 50 unidades/caja = 200 unidades)
     *
     * If no presentation is given, quantity is already in base units.
     *
     * @return array{quantity_base: float, presentation_id: int|null, presentation_quantity: float|null}
     */
    public function resolveToBaseUnit(Product $product, ?ProductPresentation $presentation, float $quantity): array
    {
        if ($presentation === null) {
            return [
                'quantity_base' => $quantity,
                'presentation_id' => null,
                'presentation_quantity' => null,
            ];
        }

        // Validate presentation belongs to this product
        if ($presentation->product_id !== $product->id) {
            throw new \InvalidArgumentException(
                "La presentación '{$presentation->name}' no pertenece al producto '{$product->name}'."
            );
        }

        return [
            'quantity_base' => $quantity * (float) $presentation->conversion_factor,
            'presentation_id' => $presentation->id,
            'presentation_quantity' => $quantity,
        ];
    }

    /**
     * Find a product by name, internal code, or barcode.
     * Returns null if no match or multiple ambiguous matches.
     */
    public function findProduct(string $search, int $companyId): ?Product
    {
        // Try exact matches first
        $product = Product::where('company_id', $companyId)
            ->where(function ($query) use ($search) {
                $query->where('internal_code', $search)
                    ->orWhere('barcode', $search);
            })
            ->first();

        if ($product) {
            return $product;
        }

        // Try name search (partial match)
        $products = Product::where('company_id', $companyId)
            ->where('name', 'like', "%{$search}%")
            ->limit(2)
            ->get();

        // Only return if exactly one match (avoid ambiguity)
        if ($products->count() === 1) {
            return $products->first();
        }

        return null;
    }
}
