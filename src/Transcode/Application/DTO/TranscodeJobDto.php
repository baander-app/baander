<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Shared\Application\PublicDiagnosticText;
use App\Shared\Domain\Model\PublicId;
use App\Transcode\Domain\Model\TranscodeJob;

final readonly class TranscodeJobDto
{
    public function __construct(
        private string $publicId,
        private string $videoId,
        private string $qualityTier,
        private string $status,
        private int $referenceCount,
        private int $totalSegments,
        private int $completedSegments,
        private float $progress,
        private ?string $videoCodec,
        private ?string $audioCodec,
        private int $videoBitrate,
        private int $audioBitrate,
        private int $width,
        private int $height,
        private float $framerate,
        private ?string $failReason,
        private string $createdAt,
        private string $updatedAt,
    ) {
    }

    public static function fromModel(TranscodeJob $job, PublicId $videoId): self
    {
        return new self(
            publicId: $job->getPublicId()->toString(),
            videoId: $videoId->toString(),
            qualityTier: $job->getQualityTierName(),
            status: $job->getStatus()->value,
            referenceCount: $job->getReferenceCount(),
            totalSegments: $job->getTotalSegments(),
            completedSegments: $job->getCompletedSegments(),
            progress: $job->getProgress(),
            videoCodec: $job->getVideoCodec(),
            audioCodec: $job->getAudioCodec(),
            videoBitrate: $job->getVideoBitrate(),
            audioBitrate: $job->getAudioBitrate(),
            width: $job->getWidth(),
            height: $job->getHeight(),
            framerate: $job->getFramerate(),
            failReason: $job->getFailReason(),
            createdAt: $job->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $job->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $totalSegments = (int) $row['total_segments'];
        $completedSegments = (int) $row['completed_segments'];

        return new self(
            publicId: (string) $row['public_id'],
            videoId: (string) $row['video_public_id'],
            qualityTier: (string) $row['quality_tier_name'],
            status: (string) $row['status'],
            referenceCount: (int) $row['reference_count'],
            totalSegments: $totalSegments,
            completedSegments: $completedSegments,
            progress: $totalSegments === 0 ? 0.0 : $completedSegments / $totalSegments * 100.0,
            videoCodec: $row['video_codec'] !== null ? (string) $row['video_codec'] : null,
            audioCodec: $row['audio_codec'] !== null ? (string) $row['audio_codec'] : null,
            videoBitrate: (int) $row['video_bitrate'],
            audioBitrate: (int) $row['audio_bitrate'],
            width: (int) $row['width'],
            height: (int) $row['height'],
            framerate: (float) $row['framerate'],
            failReason: $row['fail_reason'] !== null ? (string) $row['fail_reason'] : null,
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
            'videoId' => $this->videoId,
            'qualityTier' => $this->qualityTier,
            'status' => $this->status,
            'referenceCount' => $this->referenceCount,
            'totalSegments' => $this->totalSegments,
            'completedSegments' => $this->completedSegments,
            'progress' => $this->progress,
            'videoCodec' => $this->videoCodec,
            'audioCodec' => $this->audioCodec,
            'videoBitrate' => $this->videoBitrate,
            'audioBitrate' => $this->audioBitrate,
            'width' => $this->width,
            'height' => $this->height,
            'framerate' => $this->framerate,
            'failReason' => $this->failReason !== null ? PublicDiagnosticText::redact($this->failReason) : null,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
