<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject\VideoName;

enum SidecarKind: string
{
    case SeriesNfo = 'series_nfo';
    case SeasonNfo = 'season_nfo';
    case MovieNfo = 'movie_nfo';
    case VideoNfo = 'video_nfo';
    case Poster = 'poster';
    case Backdrop = 'backdrop';
    case SeasonPoster = 'season_poster';
    case VideoThumbnail = 'video_thumbnail';
}
