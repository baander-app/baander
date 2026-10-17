<?php

declare(strict_types=1);

namespace App\Playlist\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class ReorderSongsCommand
{
    /** @param list<Uuid> $songIds */
    public function __construct(
        public Uuid $playlistId,
        public Uuid $actorUuid,
        public array $songIds,
        public string $expectedRevision,
    ) {
    }
}
