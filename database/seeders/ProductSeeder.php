<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPresentation;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();
        $unidad = Unit::where('abbreviation', 'u')->first();
        $materiaPrima = ProductCategory::where('name', 'Materia Prima')->first();
        $terminado = ProductCategory::where('name', 'Producto Terminado')->first();
        $insumos = ProductCategory::where('name', 'Insumos')->first();

        // Medallón — materia prima, con presentación "Caja x50"
        $medallon = Product::create([
            'company_id' => $company->id,
            'category_id' => $materiaPrima->id,
            'name' => 'Medallón',
            'internal_code' => 'MED001',
            'base_unit_id' => $unidad->id,
            'requires_lot' => false,
            'requires_expiration' => false,
            'cost' => 50,
            'price' => 0,
            'status' => ProductStatus::Active,
        ]);

        ProductPresentation::create([
            'product_id' => $medallon->id,
            'name' => 'Caja x50',
            'conversion_factor' => 50,
            'is_purchase_default' => true,
            'is_sale_default' => false,
        ]);

        // Pan — materia prima
        Product::create([
            'company_id' => $company->id,
            'category_id' => $materiaPrima->id,
            'name' => 'Pan',
            'internal_code' => 'PAN001',
            'base_unit_id' => $unidad->id,
            'requires_lot' => false,
            'requires_expiration' => false,
            'cost' => 20,
            'price' => 0,
            'status' => ProductStatus::Active,
        ]);

        // Queso — materia prima
        Product::create([
            'company_id' => $company->id,
            'category_id' => $materiaPrima->id,
            'name' => 'Queso',
            'internal_code' => 'QUE001',
            'base_unit_id' => $unidad->id,
            'requires_lot' => false,
            'requires_expiration' => false,
            'cost' => 30,
            'price' => 0,
            'status' => ProductStatus::Active,
        ]);

        // Envase — insumo
        Product::create([
            'company_id' => $company->id,
            'category_id' => $insumos->id,
            'name' => 'Envase',
            'internal_code' => 'ENV001',
            'base_unit_id' => $unidad->id,
            'requires_lot' => false,
            'requires_expiration' => false,
            'cost' => 5,
            'price' => 0,
            'status' => ProductStatus::Active,
        ]);

        // Hamburguesa terminada — producto terminado, con lote y vencimiento
        $hamburguesa = Product::create([
            'company_id' => $company->id,
            'category_id' => $terminado->id,
            'name' => 'Hamburguesa',
            'internal_code' => 'HAM001',
            'base_unit_id' => $unidad->id,
            'requires_lot' => true,
            'requires_expiration' => true,
            'shelf_life_days' => 180,
            'cost' => 105,
            'price' => 200,
            'status' => ProductStatus::Active,
        ]);

        ProductPresentation::create([
            'product_id' => $hamburguesa->id,
            'name' => 'Caja x24',
            'conversion_factor' => 24,
            'is_purchase_default' => false,
            'is_sale_default' => true,
        ]);
    }
}
