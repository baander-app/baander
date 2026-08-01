<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Transcode\Domain\Model\TranscodeSession;

final readonly class TranscodeSessionDto
{
    public function __construct(
        private string $uuid,
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

    public static function fromModel(TranscodeSession $session): self
    {
        return new self(
            uuid: $session->getId()->toString(),
            publicId: $session->getPublicId()->toString(),
            userId: $session->getUserId()->toString(),
            jobId: $session->getJobId()->toString(),
            videoId: $session->getVideoId()->toString(),
            state: $session->getSessionState()->value,
            priority: $session->getPriority()->value,
            audioProfile: [
                'name' => $session->getAudioProfile()->name,
                'codec' => $session->getAudioProfile()->codec,
                'bitrate' => $session->getAudioProfile()->bitrate,
                'channelCount' => $session->getAudioProfile()->channelCount,
                'sampleRate' => $session->getAudioProfile()->sampleRate,
                'loudnessStandard' => $session->getAudioProfile()->loudnessStandard->value,
            ],
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
            uuid: (string) $row['id'],
            publicId: (string) $row['public_id'],
            userId: (string) $row['user_id'],
            jobId: (string) $row['job_id'],
            videoId: (string) $row['video_id'],
            state: (string) $row['state'],
            priority: (string) $row['priority'],
            audioProfile: \is_string($row['audio_profile']) ? json_decode($row['audio_profile'], true, 512, JSON_THROW_ON_ERROR) : (array) $row['audio_profile'],
            currentSegmentIndex: (int) $row['current_segment_index'],
            wallClockOffset: (float) $row['wall_clock_offset'],
            metrics: \is_string($row['metrics']) ? json_decode($row['metrics'], true, 512, JSON_THROW_ON_ERROR) : (array) $row['metrics'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
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
