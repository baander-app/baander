<?php

declare(strict_types=1);

namespace App\Activity\Application\Command;

enum VideoProgressAction: string
{
    case Progress = 'progress';
    case Pause = 'pause';
    case Seek = 'seek';
    case End = 'end';
}
