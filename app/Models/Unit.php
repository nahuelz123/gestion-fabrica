<?php

namespace App\Models;

use App\Enums\UnitType;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = [
        'name',
        'abbreviation',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => UnitType::class,
        ];
    }
}
