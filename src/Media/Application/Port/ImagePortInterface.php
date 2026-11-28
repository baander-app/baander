<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Media\Domain\Model\Image;
use App\Media\Domain\ReadModel\ImageReadView;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\MediaReadScope;

interface ImagePortInterface
{
    public function findVisibleByPublicId(PublicId $publicId, MediaReadScope $scope): ?ImageReadView;

    /**
     * Authorize and persist the hash without changing stored image ownership.
     * The target image must not be managed by the request's EntityManager.
     *
     * @throws \LogicException when callers have not flushed and cleared a managed target.
     */
    public function saveVisibleBlurhash(Uuid $imageId, string $blurhash, MediaReadScope $scope): bool;

    public function findByPublicId(PublicId $publicId): ?Image;

    public function findByUuid(Uuid $uuid): ?Image;

    /**
     * @param Uuid[] $uuids
     * @return array<string, Image> keyed by UUID string
     */
    public function findByUuids(array $uuids): array;

    /**
     * @return Image[]
     */
    public function findByOwner(string $imageableType, Uuid $ownerId): array;

    /** @return list<Image> */
    public function findManagedVideoArtwork(Uuid $libraryId, string $pathPrefix): array;

    public function findPrimaryForOwner(string $imageableType, Uuid $ownerId): ?Image;

    public function save(Image $image): void;

    /**
     * Makes the artist the owner of the artist image, for an image another artist's cover moved to
     * it, such as when an artist merge gives the surviving artist the merged artist's cover. Writes
     * at once, inside the caller's transaction when there is one; an image this process holds
     * loaded is read again. Changes nothing for an image that does not exist or is not an artist
     * image.
     *
     * @return bool whether the image now belongs to the artist because of this call
     */
    public function assignArtistOwner(Uuid $imageId, Uuid $artistId): bool;

    public function delete(Image $image): void;
}
