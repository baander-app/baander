<?php

declare(strict_types=1);

namespace App\Library\Application\QueryHandler;

use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Query\GetLibraryStatsQuery;
use App\Library\Application\Query\LibraryStatsQueryPort;
use App\Library\Application\Service\LibraryLookup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class GetLibraryStatsHandler
{
    public function __construct(
        private LibraryLookup $lookup,
        private LibraryStatsQueryPort $stats,
    ) {
    }

    /**
     * @return array{songs: int, albums: int, artists: int, genres: int, totalSize: int, totalDuration: float}
     *
     * @throws LibraryNotFoundException when the reader may not see the library either
     */
    #[AsMessageHandler]
    public function __invoke(GetLibraryStatsQuery $query): array
    {
        return $this->stats->getStatsForLibrary($this->lookup->byIdentifier($query->library, $query->scope)->getId());
    }
}
