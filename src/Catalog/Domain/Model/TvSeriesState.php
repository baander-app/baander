<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class TvSeriesState
{
    /**
     * @param array<string, string> $externalIds
     * @param array<string, string|int|null> $providerFields
     * @param list<string> $lockedFields
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $libraryId,
        public string $folderPath,
        public string $name,
        public ?int $year = null,
        public ?string $matchedProvider = null,
        public ?string $providerId = null,
        public array $externalIds = [],
        public string $ordering = 'aired',
        public bool $handMatched = false,
        public ?string $overview = null,
        public ?string $posterUrl = null,
        public ?string $backdropUrl = null,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
        public array $providerFields = [],
        public array $lockedFields = [],
    ) {}
}
