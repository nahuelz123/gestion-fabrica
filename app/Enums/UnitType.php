<?php

namespace App\Enums;

enum UnitType: string
{
    case Count = 'count';
    case Weight = 'weight';
    case Volume = 'volume';

    public function label(): string
    {
        return match ($this) {
            self::Count => 'Conteo',
            self::Weight => 'Peso',
            self::Volume => 'Volumen',
        };
    }
}
