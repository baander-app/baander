<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\DTO\TvTitleRecord;
use App\Metadata\Application\DTO\TvTitleMetadata;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface TvCatalogPortInterface
{
    public function registerSeries(Uuid $libraryId, TvTitleMetadata $metadata): TvTitleRecord;

    public function registerEpisode(TvTitleRecord $series, int $seasonNumber, int $episodeNumber, TvTitleMetadata $metadata): TvTitleRecord;

    public function findSeries(PublicId $publicId): ?TvTitleRecord;

    /** @return list<TvTitleRecord> */
    public function seriesByLibraryAfter(Uuid $libraryId, ?Uuid $after = null, int $limit = 100): array;

    /** @return list<TvTitleRecord> */
    public function episodesBySeriesAfter(Uuid $seriesId, ?Uuid $after = null, int $limit = 100): array;
}
