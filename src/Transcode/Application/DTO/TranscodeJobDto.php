<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Transcode\Domain\Model\TranscodeJob;

final readonly class TranscodeJobDto
{
    public function __construct(
        private string $uuid,
        private string $publicId,
        private string $videoId,
        private string $qualityTier,
        private string $status,
        private int $referenceCount,
        private int $totalSegments,
        private int $completedSegments,
        private float $progress,
        private string $outputDirectory,
        private ?string $initSegmentPath,
        private array $segmentMap,
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

    public static function fromModel(TranscodeJob $job): self
    {
        return new self(
            uuid: $job->getId()->toString(),
            publicId: $job->getPublicId()->toString(),
            videoId: $job->getVideoId()->toString(),
            qualityTier: $job->getQualityTierName(),
            status: $job->getStatus()->value,
            referenceCount: $job->getReferenceCount(),
            totalSegments: $job->getTotalSegments(),
            completedSegments: $job->getCompletedSegments(),
            progress: $job->getProgress(),
            outputDirectory: $job->getOutputDirectory(),
            initSegmentPath: $job->getInitSegmentPath(),
            segmentMap: $job->getSegmentMap(),
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
        return new self(
            uuid: (string) $row['id'],
            publicId: (string) $row['public_id'],
            videoId: (string) $row['video_id'],
            qualityTier: (string) $row['quality_tier'],
            status: (string) $row['status'],
            referenceCount: (int) $row['reference_count'],
            totalSegments: (int) $row['total_segments'],
            completedSegments: (int) $row['completed_segments'],
            progress: (float) $row['progress'],
            outputDirectory: (string) $row['output_directory'],
            initSegmentPath: $row['init_segment_path'] !== null ? (string) $row['init_segment_path'] : null,
            segmentMap: \is_string($row['segment_map']) ? json_decode($row['segment_map'], true, 512, JSON_THROW_ON_ERROR) : (array) $row['segment_map'],
            videoCodec: $row['video_codec'] !== null ? (string) $row['video_codec'] : null,
            audioCodec: $row['audio_codec'] !== null ? (string) $row['audio_codec'] : null,
            videoBitrate: (int) $row['video_bitrate'],
            audioBitrate: (int) $row['audio_bitrate'],
            width: (int) $row['width'],
            height: (int) $row['height'],
            framerate: (float) $row['framerate'],
            failReason: $row['fail_reason'] !== null ? (string) $row['fail_reason'] : null,
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
            'videoId' => $this->videoId,
            'qualityTier' => $this->qualityTier,
            'status' => $this->status,
            'referenceCount' => $this->referenceCount,
            'totalSegments' => $this->totalSegments,
            'completedSegments' => $this->completedSegments,
            'progress' => $this->progress,
            'outputDirectory' => $this->outputDirectory,
            'initSegmentPath' => $this->initSegmentPath,
            'segmentMap' => $this->segmentMap,
            'videoCodec' => $this->videoCodec,
            'audioCodec' => $this->audioCodec,
            'videoBitrate' => $this->videoBitrate,
            'audioBitrate' => $this->audioBitrate,
            'width' => $this->width,
            'height' => $this->height,
            'framerate' => $this->framerate,
            'failReason' => $this->failReason,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
