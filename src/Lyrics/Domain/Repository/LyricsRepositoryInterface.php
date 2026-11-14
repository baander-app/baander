<?php

declare(strict_types=1);

namespace App\Lyrics\Domain\Repository;

use App\Lyrics\Domain\Model\Lyrics;
use App\Shared\Domain\Model\Uuid;

interface LyricsRepositoryInterface
{
    /**
     * Stores lyrics for a song that has none.
     *
     * Safe against a concurrent store for the same song: the first one wins.
     *
     * @return bool false, storing nothing, when the song already has lyrics
     */
    public function add(Lyrics $lyrics): bool;

    public function save(Lyrics $lyrics): void;

    public function findBySongId(Uuid $songId): ?Lyrics;

    /**
     * Which of a set of songs have lyrics, in one read that loads no lyrics, so a walk over
     * every song does not grow with the library.
     *
     * @param list<Uuid> $songIds
     * @return array<string, true> keyed by song ID string; a song without lyrics, or that does not exist, is absent
     */
    public function songIdsWithLyrics(array $songIds): array;

    public function delete(Lyrics $lyrics): void;
}
