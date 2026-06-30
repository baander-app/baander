<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface CatalogStatsQueryPortInterface
{
    /**
     * @return array{songs: int, albums: int, artists: int, genres: int, totalSize: int, totalDuration: float}
     */
    public function getStatsForLibrary(Uuid $libraryId): array;
}
