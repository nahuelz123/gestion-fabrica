<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Dueño',
            self::Manager => 'Encargado',
        };
    }
}
