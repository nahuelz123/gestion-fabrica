<?php

namespace Database\Seeders;

use App\Enums\UnitType;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        Unit::create([
            'name' => 'Unidad',
            'abbreviation' => 'u',
            'type' => UnitType::Count,
        ]);

        Unit::create([
            'name' => 'Kilogramo',
            'abbreviation' => 'kg',
            'type' => UnitType::Weight,
        ]);
    }
}
