<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        ProductCategory::create(['company_id' => $company->id, 'name' => 'Materia Prima']);
        ProductCategory::create(['company_id' => $company->id, 'name' => 'Producto Terminado']);
        ProductCategory::create(['company_id' => $company->id, 'name' => 'Insumos']);
    }
}
