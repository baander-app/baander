<?php

declare(strict_types=1);

namespace App\Party\Domain\ValueObject;

enum PlaybackState: string
{
    case Playing = 'playing';
    case Paused = 'paused';
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Playing => 'Playing',
            self::Paused => 'Paused',
            self::Stopped => 'Stopped',
        };
    }
}
