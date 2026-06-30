<?php

declare(strict_types=1);

namespace App\Radio\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface CountrySubscriptionPortInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listSubscriptions(Uuid $userId): array;

    /**
     * @return array<string, mixed>
     */
    public function subscribe(Uuid $userId, ?Uuid $sourceId, string $countryCode): array;

    public function unsubscribe(Uuid $userId, Uuid $sourceId, string $countryCode): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function listAvailableCountries(): array;
}
