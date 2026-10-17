<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Shared\Domain\Model\PublicId;
use App\Transcode\Domain\Model\TranscodeSession;

final readonly class TranscodeSessionDto
{
    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $audioProfile
     */
    public function __construct(
        private string $publicId,
        private string $userId,
        private string $jobId,
        private string $videoId,
        private string $state,
        private string $priority,
        private array $audioProfile,
        private int $currentSegmentIndex,
        private float $wallClockOffset,
        private array $metrics,
        private string $createdAt,
        private string $updatedAt,
    ) {
    }

    public static function fromModel(TranscodeSession $session, PublicId $userId, PublicId $jobId, PublicId $videoId): self
    {
        return new self(
            publicId: $session->getPublicId()->toString(),
            userId: $userId->toString(),
            jobId: $jobId->toString(),
            videoId: $videoId->toString(),
            state: $session->getSessionState()->value,
            priority: $session->getPriority()->value,
            audioProfile: $session->getAudioProfile()->jsonSerialize(),
            currentSegmentIndex: $session->getCurrentSegmentIndex(),
            wallClockOffset: $session->getWallClockOffset(),
            metrics: $session->getMetrics(),
            createdAt: $session->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $session->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            publicId: (string) $row['public_id'],
            userId: (string) $row['user_public_id'],
            jobId: (string) $row['job_public_id'],
            videoId: (string) $row['video_public_id'],
            state: (string) $row['state'],
            priority: (string) $row['priority'],
            audioProfile: \is_string($row['audio_profile']) ? json_decode($row['audio_profile'], true, 512, JSON_THROW_ON_ERROR) : (array) $row['audio_profile'],
            currentSegmentIndex: (int) $row['current_segment_index'],
            wallClockOffset: (float) $row['wall_clock_offset'],
            metrics: \is_string($row['metrics']) ? json_decode($row['metrics'], true, 512, JSON_THROW_ON_ERROR) : (array) $row['metrics'],
            createdAt: ApiInstant::fromDatabase($row['created_at']),
            updatedAt: ApiInstant::fromDatabase($row['updated_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'userId' => $this->userId,
            'jobId' => $this->jobId,
            'videoId' => $this->videoId,
            'state' => $this->state,
            'priority' => $this->priority,
            'audioProfile' => $this->audioProfile,
            'currentSegmentIndex' => $this->currentSegmentIndex,
            'wallClockOffset' => $this->wallClockOffset,
            'metrics' => $this->metrics,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
