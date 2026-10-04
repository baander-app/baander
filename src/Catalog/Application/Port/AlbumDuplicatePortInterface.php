<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\ValueObject\DuplicateGroup;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

/**
 * Port for album duplicate detection and resolution operations.
 */
interface AlbumDuplicatePortInterface
{
    /** @return DuplicateGroup[] */
    public function findVisibleDuplicatesForAlbum(Uuid $albumId, LibraryReadScope $scope): array;

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
