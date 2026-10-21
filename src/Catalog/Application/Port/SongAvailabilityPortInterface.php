<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Song;

interface SongAvailabilityPortInterface
{
    /**
     * Returns the last observed source availability, without probing the filesystem.
     * Songs without an owning album or indexed source are unavailable.
     *
     * @param list<Song> $songs
     * @return array<string, bool> keyed by song UUID
     */
    public function forSongs(array $songs): array;
}
