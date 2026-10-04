<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class TranscodeJobFailed extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $jobId,
        private readonly Uuid $videoId,
        private readonly string $reason,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['job_id']),
            Uuid::fromString($payload['video_id']),
            $payload['reason'],
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    /**
     * @return array{job_id: string, video_id: string, reason: string, occurred_at: string}
     */
    public function toPayload(): array
    {
        return [
            'job_id' => $this->jobId->toString(),
            'video_id' => $this->videoId->toString(),
            'reason' => $this->reason,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'transcode.job_failed';
    }

    public function getJobId(): Uuid
    {
        return $this->jobId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
