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

    public function delete(Lyrics $lyrics): void;
}
