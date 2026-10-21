<?php

declare(strict_types=1);

namespace App\Library\Application\DTO;

/** What one finished library scan found. */
final readonly class LibraryScanSummary
{
    public function __construct(
        public string $libraryId,
        public string $name,
        public string $slug,
        public int $filesDiscovered,
        public int $filesProcessed,
        public int $filesSkipped,
        /** Directory intents committed with their index updates, awaiting asynchronous ingestion. */
        public int $directoriesQueued,
    ) {
    }
}
