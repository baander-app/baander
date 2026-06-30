<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\CountrySubscription;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class CountrySubscription
{
    private array $pendingEvents = [];

    private function __construct(
        private CountrySubscriptionState $state,
    ) {
    }

    public static function create(
        Uuid $userId,
        Uuid $sourceId,
        string $countryCode,
    ): self {
        if (trim($countryCode) === '') {
            throw new InvalidArgumentException('Country code cannot be empty.');
        }

        $now = new DateTimeImmutable();

        $sub = new self(new CountrySubscriptionState(
            id: new Uuid(),
            userId: $userId,
            sourceId: $sourceId,
            countryCode: $countryCode,
            lastSyncedAt: null,
            createdAt: $now,
        ));

        return $sub;
    }

    public static function reconstitute(CountrySubscriptionState $state): self
    {
        return new self($state);
    }

    public function markSynced(DateTimeImmutable $syncedAt): void
    {
        $this->state->lastSyncedAt = $syncedAt;
    }

    /**
     * @return list<object>
     */
    public function drainPendingEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getUserId(): Uuid
    {
        return $this->state->userId;
    }

    public function getSourceId(): Uuid
    {
        return $this->state->sourceId;
    }

    public function getCountryCode(): string
    {
        return $this->state->countryCode;
    }

    public function getLastSyncedAt(): ?DateTimeImmutable
    {
        return $this->state->lastSyncedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getState(): CountrySubscriptionState
    {
        return $this->state;
    }
}
