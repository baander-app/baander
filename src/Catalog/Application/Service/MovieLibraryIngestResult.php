<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

final readonly class MovieLibraryIngestResult
{
    /**
     * @param list<string> $videoIds
     */
    public function __construct(
        public string $libraryId,
        public string $libraryName,
        public bool $libraryCreated,
        public array $videoIds,
    ) {
    }
}
