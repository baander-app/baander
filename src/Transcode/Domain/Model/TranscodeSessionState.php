<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Domain\ValueObject\SessionState;
use DateTimeImmutable;

final class TranscodeSessionState
{
    /**
     * @param array<string, mixed> $metrics
     * @param string[] $audioLanguages BCP-47 language tags selected by user
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $userId,
        public readonly Uuid $jobId,
        public readonly Uuid $videoId,
        public SessionState $state,
        public SessionPriority $priority,
        public AudioProfile $audioProfile,
        public int $currentSegmentIndex,
        public float $wallClockOffset,
        public array $metrics,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $audioLanguages = [],
    ) {
    }
}
