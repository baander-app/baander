<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Query;

use App\Library\Application\Port\LibraryContentStatsInterface;
use App\Library\Application\Query\LibraryStatsQueryPort;
use App\Shared\Domain\Model\Uuid;

final class LibraryStatsQuery implements LibraryStatsQueryPort
{
    public function __construct(
        private readonly LibraryContentStatsInterface $contentStats,
    ) {
    }

    public function getStatsForLibrary(Uuid $libraryId): array
    {
        return $this->contentStats->getStatsForLibrary($libraryId);
    }
}
