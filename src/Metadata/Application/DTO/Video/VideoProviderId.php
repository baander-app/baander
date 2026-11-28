<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\Video;

enum VideoProviderId: string
{
    case Tvdb = 'tvdb';
    case Tmdb = 'tmdb';
    case Local = 'local';

    public function serves(VideoKind $kind): bool
    {
        return $this !== self::Tvdb || $kind === VideoKind::Series;
    }
}
