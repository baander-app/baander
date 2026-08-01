<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\RadioSession;

use App\Radio\Domain\Event\RadioSessionStarted;
use App\Radio\Domain\Event\RadioSessionStopped;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DomainException;

final class RadioSession
{
    private array $pendingEvents = [];

    private function __construct(
        private RadioSessionState $state,
    ) {
    }

    public static function create(Uuid $userId): self
    {
        $now = new DateTimeImmutable();

        return new self(new RadioSessionState(
            id: new Uuid(),
            userId: $userId,
            activeStationId: null,
            activeStreamUrl: null,
            state: 'stopped',
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    public static function reconstitute(RadioSessionState $state): self
    {
        return new self($state);
    }

    public function start(Uuid $stationId, string $streamUrl): void
    {
        if ($this->state->state === 'playing') {
            throw new DomainException('Cannot start a session that is already playing.');
        }

        $this->state->activeStationId = $stationId;
        $this->state->activeStreamUrl = $streamUrl;
        $this->state->state = 'playing';
        $this->state->updatedAt = new DateTimeImmutable();

        $this->pendingEvents[] = new RadioSessionStarted(
            userId: $this->state->userId,
            stationId: $stationId,
            streamUrl: $streamUrl,
            occurredAt: new DateTimeImmutable(),
        );
    }

    public function stop(): void
    {
        if ($this->state->state !== 'playing') {
            throw new DomainException('Cannot stop a session that is not playing.');
        }

        $this->state->activeStationId = null;
        $this->state->activeStreamUrl = null;
        $this->state->state = 'stopped';
        $this->state->updatedAt = new DateTimeImmutable();

        $this->pendingEvents[] = new RadioSessionStopped(
            userId: $this->state->userId,
            occurredAt: new DateTimeImmutable(),
        );
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

    public function getActiveStationId(): ?Uuid
    {
        return $this->state->activeStationId;
    }

    public function getActiveStreamUrl(): ?string
    {
        return $this->state->activeStreamUrl;
    }

    public function getState(): string
    {
        return $this->state->state;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getDomainState(): RadioSessionState
    {
        return $this->state;
    }
}
