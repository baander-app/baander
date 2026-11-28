<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

enum VideoKind: string
{
    case Series = 'tv';
    case Movie = 'movie';
}
