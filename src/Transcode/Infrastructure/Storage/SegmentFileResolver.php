<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Storage;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Filesystem\StoragePathBoundary;
use InvalidArgumentException;
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
        $this->resolvePath($this->basePath);
        if (!is_dir($this->basePath)) {
            return [];
        }

        $dirs = [];
        foreach (scandir($this->basePath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!is_link($this->basePath . '/' . $entry) && is_dir($this->basePath . '/' . $entry)) {
                $dirs[] = $entry;
            }
        }

        return $dirs;
    }

    public function resolveJobDirectory(Uuid $videoId, QualityTier $qualityTier): string
    {
        return $this->buildPath($videoId->toString(), $qualityTier->name);
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
        self::assertComponent($qualityTier->name);
        return sprintf('v%d_a%d_%s', $videoStreamIndex, $audioStreamIndex, $qualityTier->name);
    }

    public function resolveInitSegmentPath(Uuid $videoId, QualityTier $qualityTier): string
    {
        return $this->buildPath($videoId->toString(), $qualityTier->name, 'init.mp4');
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

        return $this->buildPath($videoId->toString(), $qualityTier->name, sprintf('%s_%d.m4s', $prefix, $segmentIndex));
    }

    // --- Audio Paths ---

    public function resolveAudioDirectory(Uuid $videoId, string $language): string
    {
        return $this->buildPath($videoId->toString(), 'audio', $language);
    }

    public function resolveAudioInitSegmentPath(Uuid $videoId, string $language): string
    {
        return $this->buildPath($videoId->toString(), 'audio', $language, 'init.mp4');
    }

    public function resolveAudioSegmentPath(Uuid $videoId, string $language, int $segmentIndex): string
    {
        return $this->buildPath($videoId->toString(), 'audio', $language, sprintf('seg_%d.m4s', $segmentIndex));
    }

    // --- Subtitle Paths ---

    public function resolveSubtitleDirectory(Uuid $videoId, string $language): string
    {
        return $this->buildPath($videoId->toString(), 'subtitles', $language);
    }

    public function resolveSubtitleSegmentPath(Uuid $videoId, string $language, string $segmentName): string
    {
        self::assertComponent($segmentName);
        return $this->buildPath($videoId->toString(), 'subtitles', $language, $segmentName . '.vtt');
    }

    public function resolvePath(string $path): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0")
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) === 1) {
            throw new InvalidArgumentException('Transcode paths must be absolute and contain no traversal components.');
        }
        StoragePathBoundary::resolve($this->basePath, $path);

        // Keep the configured root spelling: deletion walks beneath that root
        // without following links, including when the configured root is an alias.
        return $path;
    }

    private function buildPath(string ...$components): string
    {
        foreach ($components as $component) {
            self::assertComponent($component);
        }
        return $this->resolvePath(rtrim($this->basePath, '/') . '/' . implode('/', $components));
    }

    private static function assertComponent(string $component): void
    {
        if ($component === '' || $component === '.' || $component === '..'
            || strpbrk($component, "/\\\0") !== false) {
            throw new InvalidArgumentException('Transcode path components must be nonempty names without separators or null bytes.');
        }
    }
}
