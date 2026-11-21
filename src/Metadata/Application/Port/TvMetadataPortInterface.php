<?php

declare(strict_types=1);

namespace App\Metadata\Application\Port;

use App\Metadata\Application\DTO\TvTitleMetadata;

interface TvMetadataPortInterface
{
    public function series(int $tmdbId): ?TvTitleMetadata;

    public function episode(int $seriesTmdbId, int $seasonNumber, int $episodeNumber): ?TvTitleMetadata;
}
