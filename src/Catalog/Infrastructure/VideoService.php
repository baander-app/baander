<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\VideoPortInterface;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final readonly class VideoService implements VideoPortInterface
{
    public function __construct(private VideoRepositoryInterface $videos) {}

    public function findByPublicId(PublicId $publicId): ?Video
    {
        return $this->videos->findByPublicId($publicId);
    }

    public function isVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): bool
    {
        return $this->videos->isVisibleByPublicId($publicId, $scope);
    }

    public function findByUuid(Uuid $id): ?Video
    {
        return $this->videos->findByUuid($id);
    }
}
