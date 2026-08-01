<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class TranscodeJobCreated extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $jobId,
        private readonly Uuid $videoId,
        private readonly string $qualityTier,
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
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'job_id' => $this->jobId->toString(),
            'video_id' => $this->videoId->toString(),
            'quality_tier' => $this->qualityTier,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'transcode.job_created';
    }

    public function getJobId(): Uuid
    {
        return $this->jobId;
    }

    public function getVideoId(): Uuid
    {
        return $this->videoId;
    }

    public function getQualityTier(): string
    {
        return $this->qualityTier;
    }
}
