<?php

declare(strict_types=1);

namespace App\QoL\Domain\ValueObject;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

final readonly class StreamAllocation implements JsonSerializable
{
    public function __construct(
        public readonly Uuid              $jobId,
        public readonly string            $qualityTier,
        public readonly float             $predictedCost,
        public readonly DateTimeImmutable $allocatedAt = new DateTimeImmutable(),
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            jobId: Uuid::fromString($data['job_id']),
            qualityTier: (string)($data['quality_tier'] ?? ''),
            predictedCost: (float)($data['predicted_cost'] ?? 0.0),
            allocatedAt: isset($data['allocated_at'])
                ? new DateTimeImmutable($data['allocated_at'])
                : new DateTimeImmutable(),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'job_id' => $this->jobId->toString(),
            'quality_tier' => $this->qualityTier,
            'predicted_cost' => $this->predictedCost,
            'allocated_at' => $this->allocatedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
