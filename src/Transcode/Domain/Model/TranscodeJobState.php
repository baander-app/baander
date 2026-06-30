<?php

declare(strict_types=1);

namespace App\Transcode\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use DateTimeImmutable;

final class TranscodeJobState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $videoId,
        public readonly string $qualityTierName,
        public TranscodeStatus $status,
        public int $referenceCount,
        public int $totalSegments,
        public int $completedSegments,
        public string $outputDirectory,
        public ?string $initSegmentPath,
        /** @var array<string, array{path: string, size: int, duration: float}> */
        public array $segmentMap,
        /** @var array<string, mixed> */
        public array $probeData,
        public ?string $videoCodec,
        public ?string $audioCodec,
        public int $videoBitrate,
        public int $audioBitrate,
        public int $width,
        public int $height,
        public float $framerate,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $failReason = null,
        /** @var array<string, float> Measured loudness values from two-pass analysis */
        public array $measuredLoudness = [],
        /** @var string[] BCP-47 language tags for audio tracks to encode */
        public array $audioTrackLanguages = [],
        /** @var array<string, array{path: string, size: int, duration: float}> Audio segment map keyed by "{language}:{index}" */
        public array $audioSegmentMap = [],
    ) {
    }
}
