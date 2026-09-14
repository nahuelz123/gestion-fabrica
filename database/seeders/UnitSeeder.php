<?php

namespace Database\Seeders;

use App\Enums\UnitType;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        Unit::updateOrCreate(
            ['abbreviation' => 'u'],
            [
                'name' => 'Unidad',
                'type' => UnitType::Count,
            ]
        );

        Unit::updateOrCreate(
            ['abbreviation' => 'kg'],
            [
                'name' => 'Kilogramo',
                'type' => UnitType::Weight,
            ]
        );
    }
}
