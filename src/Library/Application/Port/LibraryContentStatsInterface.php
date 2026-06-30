<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface LibraryContentStatsInterface
{
    /**
     * @return array{songs: int, albums: int, artists: int, genres: int, totalSize: int, totalDuration: float}
     */
    public function getStatsForLibrary(Uuid $libraryId): array;
}
