<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Media\Domain\Model\Image;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface ImagePortInterface
{
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

    public function findPrimaryForOwner(string $imageableType, Uuid $ownerId): ?Image;

    public function save(Image $image): void;

    public function delete(Image $image): void;
}
