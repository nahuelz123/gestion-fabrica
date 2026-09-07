<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Warehouse;
use App\Enums\WarehouseType;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        Warehouse::create([
            'company_id' => $company->id,
            'name' => 'Depósito Principal',
            'type' => WarehouseType::Main,
            'address' => 'Fábrica Central',
        ]);
        
        Warehouse::create([
            'company_id' => $company->id,
            'name' => 'Cámara de Producción',
            'type' => WarehouseType::Production,
            'address' => 'Sector Producción',
        ]);
    }
}
