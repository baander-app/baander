<?php

declare(strict_types=1);

namespace App\Party\Domain\ValueObject;

enum PlaybackAction: string
{
    case Play = 'play';
    case Pause = 'pause';
    case Seek = 'seek';
    case Join = 'join';
    case Leave = 'leave';

    public function label(): string
    {
        return match ($this) {
            self::Play => 'Play',
            self::Pause => 'Pause',
            self::Seek => 'Seek',
            self::Join => 'Join',
            self::Leave => 'Leave',
        };
    }
}
