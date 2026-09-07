<?php

namespace App\Enums;

enum WarehouseType: string
{
    case Main = 'main';
    case Production = 'production';
    case Distribution = 'distribution';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Principal',
            self::Production => 'Producción',
            self::Distribution => 'Distribución',
        };
    }
}
