<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\Cover;

use App\Media\Application\Port\ImagePortInterface;
use App\Media\Domain\Model\Image;
use App\Media\Domain\ReadModel\ImageReadView;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\MediaReadScope;

/** Image records in memory. */
final class InMemoryImages implements ImagePortInterface
{
    /** @var array<string, Image> by UUID */
    public array $records = [];

    public function findVisibleByPublicId(PublicId $publicId, MediaReadScope $scope): ?ImageReadView
    {
        throw new \LogicException('Not used by the cover use cases.');
    }

    public function saveVisibleBlurhash(Uuid $imageId, string $blurhash, MediaReadScope $scope): bool
    {
        throw new \LogicException('Not used by the cover use cases.');
    }

    public function findByPublicId(PublicId $publicId): ?Image
    {
        foreach ($this->records as $image) {
            if ($image->getPublicId()->equals($publicId)) {
                return $image;
            }
        }

        return null;
    }

    public function findByUuid(Uuid $uuid): ?Image
    {
        return $this->records[$uuid->toString()] ?? null;
    }

    public function findByUuids(array $uuids): array
    {
        throw new \LogicException('Not used by the cover use cases.');
    }

    public function findByOwner(string $imageableType, Uuid $ownerId): array
    {
        throw new \LogicException('Not used by the cover use cases.');
    }

    public function findPrimaryForOwner(string $imageableType, Uuid $ownerId): ?Image
    {
        throw new \LogicException('Not used by the cover use cases.');
    }

    public function save(Image $image): void
    {
        $this->records[$image->getId()->toString()] = $image;
    }

    public function delete(Image $image): void
    {
        unset($this->records[$image->getId()->toString()]);
    }
}
