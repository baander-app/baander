<?php

declare(strict_types=1);

namespace App\Lyrics\Domain\Repository;

use App\Lyrics\Domain\Model\Lyrics;
use App\Shared\Domain\Model\Uuid;

interface LyricsRepositoryInterface
{
    public function save(Lyrics $lyrics): void;

    public function findBySongId(Uuid $songId): ?Lyrics;

    public function findByLrclibId(int $id): ?Lyrics;

    public function delete(Lyrics $lyrics): void;
}
