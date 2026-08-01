<?php

declare(strict_types=1);

namespace App\Media\Infrastructure;

use App\Media\Application\Port\ImagePortInterface;
use App\Media\Domain\Model\Image;
use App\Media\Domain\Repository\ImageRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final class ImageService implements ImagePortInterface
{
    public function __construct(
        private readonly ImageRepositoryInterface $imageRepository,
    ) {
    }

    public function findByPublicId(PublicId $publicId): ?Image
    {
        return $this->imageRepository->findByPublicId($publicId);
    }

    public function findByUuid(Uuid $uuid): ?Image
    {
        return $this->imageRepository->findByUuid($uuid);
    }

    public function findByUuids(array $uuids): array
    {
        return $this->imageRepository->findByUuids($uuids);
    }

    /**
     * @return Image[]
     */
    public function findByOwner(string $imageableType, Uuid $ownerId): array
    {
        return $this->imageRepository->findByOwner($imageableType, $ownerId);
    }

    public function findPrimaryForOwner(string $imageableType, Uuid $ownerId): ?Image
    {
        return $this->imageRepository->findPrimaryForOwner($imageableType, $ownerId);
    }

    public function save(Image $image): void
    {
        $this->imageRepository->save($image);
    }

    public function delete(Image $image): void
    {
        $this->imageRepository->delete($image);
    }
}
