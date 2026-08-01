<?php

declare(strict_types=1);

namespace App\Metadata\Application;

final readonly class SyncLibraryResult
{
    public function __construct(
        public int $albumsDispatched,
        public int $songsDispatched,
        public int $artistsDispatched,
    )
    {
    }

    public function totalDispatched(): int
    {
        return $this->albumsDispatched + $this->songsDispatched + $this->artistsDispatched;
    }
}
