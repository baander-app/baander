<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\CountrySubscription;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class CountrySubscriptionState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $userId,
        public readonly Uuid $sourceId,
        public readonly string $countryCode,
        public ?DateTimeImmutable $lastSyncedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
