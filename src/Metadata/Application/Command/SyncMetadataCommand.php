<?php

declare(strict_types=1);

namespace App\Metadata\Application\Command;

/**
 * Starts an administrator's metadata sync: without a source, one library sync for every
 * library; with the `genres` source, only the genre sync, which re-syncs every album and
 * song with forced updates.
 */
final readonly class SyncMetadataCommand
{
    public const string SOURCE_GENRES = 'genres';

    public function __construct(
        public ?string $source = null,
    ) {
    }
}
