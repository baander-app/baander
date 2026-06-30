<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\RadioStation;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class RadioStationState
{
    /**
     * @param Uuid $id
     * @param Uuid $sourceId
     * @param string $externalId
     * @param string $name
     * @param string $country
     * @param string|null $language
     * @param list<string> $genres
     * @param list<string> $tags
     * @param list<Stream> $streams
     * @param string|null $logo
     * @param string|null $website
     * @param DateTimeImmutable|null $lastCheckedAt
     * @param DateTimeImmutable $createdAt
     * @param DateTimeImmutable $updatedAt
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly Uuid $sourceId,
        public string $externalId,
        public string $name,
        public string $country,
        public ?string $language,
        public array $genres,
        public array $tags,
        public array $streams,
        public ?string $logo,
        public ?string $website,
        public ?DateTimeImmutable $lastCheckedAt,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
