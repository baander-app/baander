<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Dispatched when a client pauses, seeks, or resumes playback during transcoding.
 *
 * Carries the new playback position and the action type so the encoding loop can
 * reorganize its segment queue around the new position or pause dispatching.
 */
final readonly class PlaybackPositionChanged extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $jobId,
        private readonly float $position,
        private readonly string $action,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['job_id']),
            (float) $payload['position'],
            $payload['action'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'job_id' => $this->jobId->toString(),
            'position' => $this->position,
            'action' => $this->action,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'transcode.playback_position_changed';
    }

    public function getJobId(): Uuid
    {
        return $this->jobId;
    }

    public function getPosition(): float
    {
        return $this->position;
    }

    public function getAction(): string
    {
        return $this->action;
    }
}
