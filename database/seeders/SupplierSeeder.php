<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::first();

        Supplier::create([
            'company_id' => $company->id,
            'name' => 'Proveedor Mayorista S.A.',
            'tax_id' => '30-12345678-9',
            'email' => 'ventas@mayorista.com',
            'phone' => '11-4444-5555',
            'address' => 'Av. San Martín 1234, CABA',
        ]);

        Supplier::create([
            'company_id' => $company->id,
            'name' => 'Insumos Industriales SRL',
            'tax_id' => '30-98765432-1',
            'email' => 'contacto@insumos.com',
            'phone' => '11-6666-7777',
            'address' => 'Ruta 8 Km 22, San Martín',
        ]);
    }
}
