<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Storage;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\ValueObject\QualityTier;

final class TranscodeFileStorage implements TranscodeStoragePortInterface
{
    public function __construct(
        private readonly SegmentFileResolver $resolver,
    ) {
    }

    public function resolveJobDirectory(Uuid $videoId, QualityTier $qualityTier): string
    {
        return $this->resolver->resolveJobDirectory($videoId, $qualityTier);
    }

    public function resolveInitSegmentPath(Uuid $videoId, QualityTier $qualityTier): string
    {
        return $this->resolver->resolveInitSegmentPath($videoId, $qualityTier);
    }

    public function resolveSegmentPath(Uuid $videoId, QualityTier $qualityTier, int $segmentIndex): string
    {
        return $this->resolver->resolveSegmentPath($videoId, $qualityTier, $segmentIndex);
    }

    public function resolveAudioDirectory(Uuid $videoId, string $language): string
    {
        return $this->resolver->resolveAudioDirectory($videoId, $language);
    }

    public function resolveAudioInitSegmentPath(Uuid $videoId, string $language): string
    {
        return $this->resolver->resolveAudioInitSegmentPath($videoId, $language);
    }

    public function resolveAudioSegmentPath(Uuid $videoId, string $language, int $segmentIndex): string
    {
        return $this->resolver->resolveAudioSegmentPath($videoId, $language, $segmentIndex);
    }

    public function resolveSubtitleDirectory(Uuid $videoId, string $language): string
    {
        return $this->resolver->resolveSubtitleDirectory($videoId, $language);
    }

    public function resolveSubtitleSegmentPath(Uuid $videoId, string $language, string $segmentName): string
    {
        return $this->resolver->resolveSubtitleSegmentPath($videoId, $language, $segmentName);
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $it = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($path);
    }

    public function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $it = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it);

        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
