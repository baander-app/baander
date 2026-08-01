<?php

declare(strict_types=1);

namespace App\Discovery\Domain\ValueObject;

enum ServerStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Maintenance => 'Maintenance',
        };
    }
}
