<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Dto\VideoSourceMetadata;
use App\Catalog\Domain\Model\Video;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface VideoPortInterface
{
    public function getSourceMetadata(PublicId $publicId, LibraryReadScope $scope): ?VideoSourceMetadata;

    public function findByPublicId(PublicId $publicId): ?Video;

    public function isVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): bool;

    public function findByUuid(Uuid $id): ?Video;
}
