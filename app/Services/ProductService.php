<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPresentation;
use App\Models\Unit;

class ProductService
{
    public function createProduct(int $companyId, array $data): Product
    {
        $unit = Unit::firstOrCreate(
            ['abbreviation' => 'u'],
            ['name' => 'Unidad', 'type' => 'count']
        );

        $categoryName = ($data['type'] ?? 'raw_material') === 'raw_material' ? 'Insumos' : 'Producto Terminado';
        $category = ProductCategory::firstOrCreate(
            ['company_id' => $companyId, 'name' => $categoryName]
        );

        $productData = [
            'company_id' => $companyId,
            'category_id' => $category->id,
            'type' => $data['type'] ?? 'raw_material',
            'name' => $data['name'],
            'presentation' => $data['presentation'],
            'cost' => 0,
            'price' => 0,
            'base_unit_id' => $unit->id,
        ];

        if ($productData['type'] === 'finished_product') {
            $productData['barcode'] = $data['barcode'] ?? null;
            $productData['requires_lot'] = $data['requires_lot'] ?? false;
            $productData['requires_expiration'] = $data['requires_expiration'] ?? false;
            $productData['shelf_life_days'] = !empty($productData['requires_expiration']) ? ($data['shelf_life_days'] ?? null) : null;
        } else {
            $productData['barcode'] = null;
            $productData['requires_lot'] = false;
            $productData['requires_expiration'] = false;
            $productData['shelf_life_days'] = null;
        }

        $product = Product::create($productData);

        ProductPresentation::create([
            'product_id' => $product->id,
            'conversion_factor' => 1.0000,
            'name' => $data['presentation'],
            'is_purchase_default' => true,
            'is_sale_default' => true,
            'barcode' => $productData['type'] === 'finished_product' ? $productData['barcode'] : null,
        ]);

        return $product;
    }
}