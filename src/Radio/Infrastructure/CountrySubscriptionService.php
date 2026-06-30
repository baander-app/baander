<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure;

use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Application\Port\StationSyncPortInterface;
use App\Radio\Domain\Model\CountrySubscription\CountrySubscription;
use App\Radio\Domain\Model\RadioSource\RadioSource;
use App\Radio\Domain\Repository\CountrySubscription\CountrySubscriptionRepositoryInterface;
use App\Radio\Domain\Repository\RadioSource\RadioSourceRepositoryInterface;
use App\Radio\Domain\ValueObject\SyncConfig;
use App\Shared\Domain\Model\Uuid;
use RuntimeException;

final class CountrySubscriptionService implements CountrySubscriptionPortInterface
{
    public function __construct(
        private readonly CountrySubscriptionRepositoryInterface $repository,
        private readonly RadioSourceRepositoryInterface $sourceRepository,
        private readonly StationSyncPortInterface $syncAdapter,
    ) {
    }

    private function ensureActiveSource(): RadioSource
    {
        $sources = $this->sourceRepository->findActive();
        if (!empty($sources)) {
            return reset($sources);
        }

        // No active source — auto-create the default IPRD source
        $source = RadioSource::create(
            name: 'IPRD',
            type: 'iprd',
            syncConfig: new SyncConfig(
                syncUrl: 'https://iprd-org.github.io/iprd',
                schedule: null,
                config: [],
            ),
        );
        $this->sourceRepository->save($source);

        return $source;
    }

    public function listSubscriptions(Uuid $userId): array
    {
        $subscriptions = $this->repository->findByUserId($userId);

        return array_map($this->subscriptionToArray(...), $subscriptions);
    }

    public function subscribe(Uuid $userId, ?Uuid $sourceId, string $countryCode): array
    {
        // If no sourceId provided, auto-resolve to an active source (creating one if needed)
        if ($sourceId === null) {
            $source = $this->ensureActiveSource();
            $sourceId = $source->getId();
        }

        $existing = $this->repository->findByUserAndSourceAndCountry($userId, $sourceId, $countryCode);

        if ($existing !== null) {
            throw new RuntimeException('Already subscribed to this country.');
        }

        $subscription = CountrySubscription::create($userId, $sourceId, $countryCode);
        $this->repository->save($subscription);

        return $this->subscriptionToArray($subscription);
    }

    public function unsubscribe(Uuid $userId, Uuid $sourceId, string $countryCode): void
    {
        $subscription = $this->repository->findByUserAndSourceAndCountry($userId, $sourceId, $countryCode);

        if ($subscription === null) {
            throw new RuntimeException('Not subscribed to this country.');
        }

        $this->repository->remove($subscription);
    }

    public function listAvailableCountries(): array
    {
        return $this->syncAdapter->fetchCountries();
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionToArray(CountrySubscription $subscription): array
    {
        return [
            'id' => $subscription->getId()->toString(),
            'userId' => $subscription->getUserId()->toString(),
            'sourceId' => $subscription->getSourceId()->toString(),
            'countryCode' => $subscription->getCountryCode(),
            'lastSyncedAt' => $subscription->getLastSyncedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $subscription->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
