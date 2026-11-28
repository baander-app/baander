<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class TvEpisodeState
{
    /**
     * @param array<string, string> $externalIds
     * @param array<string, string|int|null> $providerFields
     * @param list<string> $lockedFields
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $seriesId,
        public ?Uuid $seasonId,
        public ?int $episodeNumber,
        public string $name,
        public ?int $absoluteNumber = null,
        public ?DateTimeImmutable $airDate = null,
        public ?string $providerId = null,
        public array $externalIds = [],
        public ?string $overview = null,
        public ?string $stillUrl = null,
        public bool $retired = false,
        public array $providerFields = [],
        public array $lockedFields = [],
    ) {}
}
