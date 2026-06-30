<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use DateTimeImmutable;
use App\Transcode\Domain\Exception\EmptyOutputDirectoryException;
use App\Transcode\Domain\Exception\InvalidJobTransitionException;

final class TranscodeJob
{
    private function __construct(
        private TranscodeJobState $state,
    )
    {
    }

    public static function create(
        Uuid $videoId,
        QualityTier $qualityTier,
        string $outputDirectory,
        array $audioTrackLanguages = [],
    ): self
    {
        if (trim($outputDirectory) === '') {
            throw EmptyOutputDirectoryException::create();
        }

        return new self(new TranscodeJobState(
            id: new Uuid(),
            publicId: new PublicId(),
            videoId: $videoId,
            qualityTierName: $qualityTier->name,
            status: TranscodeStatus::Pending,
            referenceCount: 0,
            totalSegments: 0,
            completedSegments: 0,
            outputDirectory: $outputDirectory,
            initSegmentPath: null,
            segmentMap: [],
            probeData: [],
            videoCodec: null,
            audioCodec: null,
            videoBitrate: 0,
            audioBitrate: 0,
            width: $qualityTier->width,
            height: $qualityTier->height,
            framerate: 0.0,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            measuredLoudness: [],
            audioTrackLanguages: $audioTrackLanguages,
            audioSegmentMap: [],
        ));
    }

    public static function reconstitute(TranscodeJobState $state): self
    {
        return new self($state);
    }

    public function attachSession(): void
    {
        $this->state->referenceCount++;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function detachSession(): bool
    {
        if ($this->state->referenceCount <= 0) {
            return false;
        }

        $this->state->referenceCount--;
        $this->state->updatedAt = new DateTimeImmutable();

        return $this->state->referenceCount === 0;
    }

    public function markInProgress(): void
    {
        if ($this->state->status !== TranscodeStatus::Pending) {
            throw InvalidJobTransitionException::fromStatus($this->state->status->value, TranscodeStatus::InProgress->value);
        }

        $this->state->status = TranscodeStatus::InProgress;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateProbeData(array $probeData): void
    {
        $this->state->probeData = $probeData;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function setTotalSegments(int $total): void
    {
        $this->state->totalSegments = $total;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function setInitSegmentPath(string $path): void
    {
        $this->state->initSegmentPath = $path;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markSegmentCompleted(int $index, string $path, int $size, float $duration): void
    {
        if ($index < 0 || ($this->state->totalSegments > 0 && $index >= $this->state->totalSegments)) {
            return;
        }

        $this->state->segmentMap[(string)$index] = [
            'path'     => $path,
            'size'     => $size,
            'duration' => $duration,
        ];
        $this->state->completedSegments = count($this->state->segmentMap);
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markCompleted(): void
    {
        if ($this->state->status !== TranscodeStatus::InProgress) {
            throw InvalidJobTransitionException::fromStatus($this->state->status->value, TranscodeStatus::Completed->value);
        }

        $this->state->status = TranscodeStatus::Completed;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markFailed(string $reason): void
    {
        if ($this->state->status === TranscodeStatus::Completed || $this->state->status === TranscodeStatus::Cancelled || $this->state->status === TranscodeStatus::Failed) {
            return;
        }

        $this->state->failReason = $reason;
        $this->state->status = TranscodeStatus::Failed;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markCancelled(): void
    {
        if ($this->state->status === TranscodeStatus::Completed || $this->state->status === TranscodeStatus::Failed) {
            return;
        }

        $this->state->status = TranscodeStatus::Cancelled;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getProgress(): float
    {
        if ($this->state->totalSegments === 0) {
            return 0.0;
        }

        return ($this->state->completedSegments / $this->state->totalSegments) * 100.0;
    }

    public function getState(): TranscodeJobState
    {
        return $this->state;
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function getVideoId(): Uuid
    {
        return $this->state->videoId;
    }

    public function getQualityTierName(): string
    {
        return $this->state->qualityTierName;
    }

    public function getStatus(): TranscodeStatus
    {
        return $this->state->status;
    }

    public function getReferenceCount(): int
    {
        return $this->state->referenceCount;
    }

    public function getTotalSegments(): int
    {
        return $this->state->totalSegments;
    }

    public function getCompletedSegments(): int
    {
        return $this->state->completedSegments;
    }

    public function getOutputDirectory(): string
    {
        return $this->state->outputDirectory;
    }

    public function getInitSegmentPath(): ?string
    {
        return $this->state->initSegmentPath;
    }

    /** @return array<string, array{path: string, size: int, duration: float}> */
    public function getSegmentMap(): array
    {
        return $this->state->segmentMap;
    }

    /** @return array<string, mixed> */
    public function getProbeData(): array
    {
        return $this->state->probeData;
    }

    public function getMeasuredLoudness(): array
    {
        return $this->state->measuredLoudness;
    }

    public function setMeasuredLoudness(array $loudness): void
    {
        $this->state->measuredLoudness = $loudness;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function getVideoCodec(): ?string
    {
        return $this->state->videoCodec;
    }

    public function getAudioCodec(): ?string
    {
        return $this->state->audioCodec;
    }

    public function getVideoBitrate(): int
    {
        return $this->state->videoBitrate;
    }

    public function getAudioBitrate(): int
    {
        return $this->state->audioBitrate;
    }

    public function getWidth(): int
    {
        return $this->state->width;
    }

    public function getHeight(): int
    {
        return $this->state->height;
    }

    public function getFramerate(): float
    {
        return $this->state->framerate;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getFailReason(): ?string
    {
        return $this->state->failReason;
    }

    /** @return string[] */
    public function getAudioTrackLanguages(): array
    {
        return $this->state->audioTrackLanguages;
    }

    /**
     * @param string[] $languages
     */
    public function setAudioTrackLanguages(array $languages): void
    {
        $this->state->audioTrackLanguages = $languages;
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function markAudioSegmentCompleted(string $language, int $index, string $path, int $size, float $duration): void
    {
        $key = sprintf('%s:%d', $language, $index);
        $this->state->audioSegmentMap[$key] = [
            'path'     => $path,
            'size'     => $size,
            'duration' => $duration,
        ];
        $this->state->updatedAt = new DateTimeImmutable();
    }

    /** @return array<string, array{path: string, size: int, duration: float}> */
    public function getAudioSegmentMap(): array
    {
        return $this->state->audioSegmentMap;
    }
}
