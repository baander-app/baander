<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Video;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface VideoPortInterface
{
    public function findByPublicId(PublicId $publicId): ?Video;

    public function findByUuid(Uuid $id): ?Video;
}
