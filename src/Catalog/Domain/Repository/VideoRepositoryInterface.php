<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\ReadModel\VideoPlacementPath;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface VideoRepositoryInterface
{
    public function save(Video $video): void;

    public function findByUuid(Uuid $uuid): ?Video;

    public function findFreshByUuid(Uuid $uuid): ?Video;

    public function findByPublicId(PublicId $publicId): ?Video;

    public function isVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): bool;

    /** @return list<Uuid> */
    public function getLibraryIdsByPublicId(PublicId $publicId, LibraryReadScope $scope): array;

    /**
     * @param list<Uuid> $videoIds
     * @return list<VideoPlacementPath>
     */
    public function placementPaths(array $videoIds, LibraryReadScope $scope): array;

    /** @return list<Uuid> Requires a transaction and locks the movie against concurrent deletion. */
    public function movieVideoIdsForDelete(Uuid $movieId): array;

    public function findByHash(string $hash): ?Video;

    /**
     * @return Video[]
     */
    public function findByMovie(Uuid $movieId): array;

    public function count(): int;

    /**
     * Deletes videos with neither a movie link nor any placement and returns the deleted count.
     *
     * @param list<Uuid> $videoIds
     */
    public function deleteUnlinked(array $videoIds): int;
}
