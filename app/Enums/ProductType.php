<?php

namespace App\Enums;

enum ProductType: string
{
    case RawMaterial = 'raw_material';
    case FinishedProduct = 'finished_product';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Insumo',
            self::FinishedProduct => 'Producto Final',
        };
    }
}
