<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Media\Application\DTO\PreparedVideoArtwork;
use App\Shared\Domain\Model\Uuid;

interface VideoArtworkImportInterface
{
    /** Reads and stores guarded local artwork before the caller takes catalog locks. */
    public function prepare(Uuid $libraryId, string $absolutePath): PreparedVideoArtwork;

    /** Saves its image row inside the caller's catalog transaction. */
    public function persist(PreparedVideoArtwork $artwork): void;

    /** Removes the prepared file after a failed apply, or when no field used it. */
    public function discard(PreparedVideoArtwork $artwork): void;
}
