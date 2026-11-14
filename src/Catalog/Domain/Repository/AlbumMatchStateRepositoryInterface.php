<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Shared\Domain\Model\Uuid;

/**
 * Each album's match state: at most one per album, removed with the album.
 */
interface AlbumMatchStateRepositoryInterface
{
    public function findByAlbum(Uuid $albumId): ?AlbumMatchState;

    /**
     * Store the album's match state in place of the one it had, in one statement; of two writes at
     * once, the later one wins. Callers that read the state before writing it serialize those steps
     * for one album, for example under the album's row lock.
     */
    public function save(AlbumMatchState $state): void;

    /**
     * Remove the album's match state; an album without one is left as it is.
     */
    public function removeByAlbum(Uuid $albumId): void;
}
