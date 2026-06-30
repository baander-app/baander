<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\ValueObject\DuplicateGroup;
use App\Shared\Domain\Model\Uuid;

/**
 * Port for album duplicate detection and resolution operations.
 */
interface AlbumDuplicatePortInterface
{
    /**
     * Finds all duplicate album groups within a library.
     *
     * @return DuplicateGroup[]
     */
    public function findDuplicates(Uuid $libraryId): array;

    /**
     * Finds duplicate groups that contain a specific album.
     *
     * @return DuplicateGroup[]
     */
    public function findDuplicatesForAlbum(Uuid $albumId): array;
}
