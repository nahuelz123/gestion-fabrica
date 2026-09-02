<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::create([
            'name' => 'Fábrica de Congelados',
            'tax_id' => '30-12345678-9',
            'address' => 'Zona Industrial S/N',
            'phone' => '1234567890',
        ]);
    }
}
