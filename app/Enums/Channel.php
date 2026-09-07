<?php

namespace App\Enums;

enum Channel: string
{
    case Web = 'web';
    case Telegram = 'telegram';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Telegram => 'Telegram',
            self::System => 'Sistema',
        };
    }
}
