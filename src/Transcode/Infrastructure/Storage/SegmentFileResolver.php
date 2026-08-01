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

    /**
     * Absolute path of the cache root holding one subdir per videoId.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * List the per-video cache directories currently on disk.
     *
     * @return list<string>
     */
    public function getVideoDirectories(): array
    {
        if (!is_dir($this->basePath)) {
            return [];
        }

        $dirs = [];
        foreach (scandir($this->basePath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($this->basePath . '/' . $entry)) {
                $dirs[] = $entry;
            }
        }

        return $dirs;
    }

    public function resolveJobDirectory(Uuid $videoId, QualityTier $qualityTier): string
    {
        return sprintf('%s/%s/%s', $this->basePath, $videoId->toString(), $qualityTier->name);
    }

    /**
     * Build the rendition-identity prefix shared by all segment/init filenames
     * for a given (video stream, audio stream, quality tier) combination.
     *
     * Encoding the full identity in the filename (not just the directory) makes
     * the on-disk cache self-describing: a sweep job can identify each file's
     * provenance without parsing the path, and a re-encode that selects
     * different source streams produces a different filename (no stale
     * collision — seek-back still hits the correct cached file).
     */
    public function segmentPrefix(int $videoStreamIndex, int $audioStreamIndex, QualityTier $qualityTier): string
    {
        return sprintf('v%d_a%d_%s', $videoStreamIndex, $audioStreamIndex, $qualityTier->name);
    }

    public function resolveInitSegmentPath(Uuid $videoId, QualityTier $qualityTier): string
    {
        return sprintf('%s/%s/%s/init.mp4', $this->basePath, $videoId->toString(), $qualityTier->name);
    }

    public function resolveSegmentPath(Uuid $videoId, QualityTier $qualityTier, int $segmentIndex): string
    {
        return $this->resolveSegmentPathForRendition($videoId, 0, 0, $qualityTier, $segmentIndex);
    }

    /**
     * Resolve the cached path for a specific muxed rendition.
     *
     * Defaults to the first video + first audio stream (v0_a0) via
     * resolveSegmentPath(); callers that select non-default streams use this.
     */
    public function resolveSegmentPathForRendition(
        Uuid $videoId,
        int $videoStreamIndex,
        int $audioStreamIndex,
        QualityTier $qualityTier,
        int $segmentIndex,
    ): string {
        $prefix = $this->segmentPrefix($videoStreamIndex, $audioStreamIndex, $qualityTier);

        return sprintf('%s/%s/%s/%s_%d.m4s', $this->basePath, $videoId->toString(), $qualityTier->name, $prefix, $segmentIndex);
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
