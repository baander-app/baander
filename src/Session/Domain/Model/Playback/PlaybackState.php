<?php

declare(strict_types=1);

namespace App\Session\Domain\Model\Playback;

enum PlaybackState: string
{
    case Playing = 'playing';
    case Paused = 'paused';
    case Stopped = 'stopped';
}
