<?php

namespace App\Enums;

enum StockLotStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Depleted = 'depleted';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Expired => 'Vencido',
            self::Depleted => 'Agotado',
            self::Blocked => 'Bloqueado',
        };
    }
}
