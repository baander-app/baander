<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Event;

use App\Shared\Domain\Event\AbstractDomainEvent;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

final readonly class TranscodeSessionAttached extends AbstractDomainEvent
{
    public function __construct(
        private readonly Uuid $sessionId,
        private readonly Uuid $jobId,
        private readonly Uuid $userId,
        private readonly string $qualityTier,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($occurredAt);
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['session_id']),
            Uuid::fromString($payload['job_id']),
            Uuid::fromString($payload['user_id']),
            (string) ($payload['quality_tier'] ?? ''),
            new DateTimeImmutable($payload['occurred_at']),
        );
    }

    public function toPayload(): array
    {
        return [
            'session_id' => $this->sessionId->toString(),
            'job_id' => $this->jobId->toString(),
            'user_id' => $this->userId->toString(),
            'quality_tier' => $this->qualityTier,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function eventName(): string
    {
        return 'transcode.session_attached';
    }

    public function getSessionId(): Uuid
    {
        return $this->sessionId;
    }

    public function getJobId(): Uuid
    {
        return $this->jobId;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getQualityTier(): string
    {
        return $this->qualityTier;
    }
}
