<?php

declare(strict_types=1);

namespace App\Media\Domain\Repository;

use App\Media\Domain\Model\Image;
use App\Media\Domain\ReadModel\ImageReadView;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\MediaReadScope;

interface ImageRepositoryInterface
{
    public function findVisibleByPublicId(PublicId $publicId, MediaReadScope $scope): ?ImageReadView;

    /**
     * Atomically authorize and write the hash without changing image ownership.
     * The target ImageEntity must not be managed by the current EntityManager.
     *
     * @throws \LogicException when the target is managed; callers must explicitly flush and clear it first.
     */
    public function saveVisibleBlurhash(Uuid $imageId, string $blurhash, MediaReadScope $scope): bool;

    public function save(Image $image): void;

    public function findByUuid(Uuid $uuid): ?Image;

    /**
     * @param Uuid[] $uuids
     * @return array<string, Image> keyed by UUID string
     */
    public function findByUuids(array $uuids): array;

    public function findByPublicId(PublicId $publicId): ?Image;

    /**
     * Find images for a given owner (album, artist, or playlist).
     *
     * @return Image[]
     */
    public function findByOwner(string $imageableType, Uuid $ownerId): array;

    /**
     * Find the primary/first image for a given owner.
     */
    public function findPrimaryForOwner(string $imageableType, Uuid $ownerId): ?Image;

    /**
     * Find all images, paginated by cursor.
     *
     * @return Image[]
     */
    public function findAllAfter(?Uuid $cursor, int $limit): array;

    /**
     * Count all images.
     */
    public function countAll(): int;

    /**
     * Find all images, offset-based paginated.
     *
     * @return Image[]
     */
    public function findAll(int $limit, int $offset): array;

    public function delete(Image $image): void;
}
