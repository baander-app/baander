<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Storage;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\ValueObject\QualityTier;

final class SegmentFileResolver
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function resolveJobDirectory(Uuid $videoId, QualityTier $qualityTier): string
    {
        return sprintf('%s/%s/%s', $this->basePath, $videoId->toString(), $qualityTier->name);
    }

    public function resolveInitSegmentPath(Uuid $videoId, QualityTier $qualityTier): string
    {
        return sprintf('%s/%s/%s/init.mp4', $this->basePath, $videoId->toString(), $qualityTier->name);
    }

    public function resolveSegmentPath(Uuid $videoId, QualityTier $qualityTier, int $segmentIndex): string
    {
        return sprintf('%s/%s/%s/seg_%d.m4s', $this->basePath, $videoId->toString(), $qualityTier->name, $segmentIndex);
    }

    // --- Audio Paths ---

    public function resolveAudioDirectory(Uuid $videoId, string $language): string
    {
        return sprintf('%s/%s/audio/%s', $this->basePath, $videoId->toString(), $language);
    }

    public function resolveAudioInitSegmentPath(Uuid $videoId, string $language): string
    {
        return sprintf('%s/%s/audio/%s/init.mp4', $this->basePath, $videoId->toString(), $language);
    }

    public function resolveAudioSegmentPath(Uuid $videoId, string $language, int $segmentIndex): string
    {
        return sprintf('%s/%s/audio/%s/seg_%d.m4s', $this->basePath, $videoId->toString(), $language, $segmentIndex);
    }

    // --- Subtitle Paths ---

    public function resolveSubtitleDirectory(Uuid $videoId, string $language): string
    {
        return sprintf('%s/%s/subtitles/%s', $this->basePath, $videoId->toString(), $language);
    }

    public function resolveSubtitleSegmentPath(Uuid $videoId, string $language, string $segmentName): string
    {
        return sprintf('%s/%s/subtitles/%s/%s.vtt', $this->basePath, $videoId->toString(), $language, $segmentName);
    }
}
