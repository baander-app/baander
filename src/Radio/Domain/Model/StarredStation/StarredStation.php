<?php

declare(strict_types=1);

namespace App\Radio\Domain\Model\StarredStation;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final class StarredStation
{
    private array $pendingEvents = [];

    private function __construct(
        private StarredStationState $state,
    ) {
    }

    public static function create(
        Uuid $userId,
        Uuid $stationId,
    ): self {
        $now = new DateTimeImmutable();

        return new self(new StarredStationState(
            id: new Uuid(),
            userId: $userId,
            stationId: $stationId,
            starredAt: $now,
        ));
    }

    public static function reconstitute(StarredStationState $state): self
    {
        return new self($state);
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

    public function getStationId(): Uuid
    {
        return $this->state->stationId;
    }

    public function getStarredAt(): DateTimeImmutable
    {
        return $this->state->starredAt;
    }

    public function getState(): StarredStationState
    {
        return $this->state;
    }
}
