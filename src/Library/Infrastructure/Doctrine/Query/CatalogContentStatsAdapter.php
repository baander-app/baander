<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Doctrine\Query;

use App\Catalog\Application\Port\CatalogStatsQueryPortInterface;
use App\Library\Application\Port\LibraryContentStatsInterface;
use App\Shared\Domain\Model\Uuid;

/**
 * Anti-corruption adapter satisfying the Library port by delegating to the
 * Catalog query port. Keeps Library decoupled from Catalog's infrastructure.
 */
final class CatalogContentStatsAdapter implements LibraryContentStatsInterface
{
    public function __construct(
        private readonly CatalogStatsQueryPortInterface $catalogStats,
    ) {
    }

    public function getStatsForLibrary(Uuid $libraryId): array
    {
        return $this->catalogStats->getStatsForLibrary($libraryId);
    }
}
