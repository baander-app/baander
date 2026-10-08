<?php

declare(strict_types=1);

namespace App\Radio\Domain\Repository\CountrySubscription;

use App\Radio\Domain\Model\CountrySubscription\CountrySubscription;
use App\Shared\Domain\Model\Uuid;

interface CountrySubscriptionRepositoryInterface
{
    public function find(Uuid $id): ?CountrySubscription;

    /** @return list<CountrySubscription> */
    public function findByUserId(Uuid $userId): array;

    /**
     * The country codes at least one user subscribes to, each once, in alphabetical order.
     *
     * @return list<string>
     */
    public function findSubscribedCountryCodes(): array;

    public function findByUserAndSourceAndCountry(Uuid $userId, Uuid $sourceId, string $countryCode): ?CountrySubscription;

    public function save(CountrySubscription $subscription): void;

    public function remove(CountrySubscription $subscription): void;
}
