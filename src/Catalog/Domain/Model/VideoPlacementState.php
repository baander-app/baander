<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class VideoPlacementState
{
    /**
     * @param array<string, mixed> $rawParse
     * @param list<Uuid> $episodeIds
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $libraryId,
        public readonly Uuid $videoId,
        public string $relativePath,
        public array $rawParse,
        public ?Uuid $seriesId = null,
        public ?Uuid $movieId = null,
        public ?ExtraKind $extraKind = null,
        public array $episodeIds = [],
        public bool $assigned = false,
        public ?string $reviewReason = null,
        public int $sortOrder = 0,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {}
}
