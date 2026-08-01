<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class TranscodeJobCompleted extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $jobId,
        private readonly Uuid $videoId,
        private readonly string $qualityTier,
        private readonly int $totalSegments,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['job_id']),
            Uuid::fromString($payload['video_id']),
            $payload['quality_tier'],
            $payload['total_segments'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'job_id' => $this->jobId->toString(),
            'video_id' => $this->videoId->toString(),
            'quality_tier' => $this->qualityTier,
            'total_segments' => $this->totalSegments,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'transcode.job_completed';
    }

    public function getJobId(): Uuid
    {
        return $this->jobId;
    }

    public function getTotalSegments(): int
    {
        return $this->totalSegments;
    }
}
