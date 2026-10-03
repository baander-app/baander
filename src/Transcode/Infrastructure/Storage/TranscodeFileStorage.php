<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Storage;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\ValueObject\QualityTier;
use InvalidArgumentException;
use RuntimeException;

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
        return file_exists($this->resolver->resolvePath($path));
    }

    public function deleteDirectory(string $path): void
    {
        $basePath = self::normalizeDeletionPath($this->resolver->getBasePath());
        $path = self::normalizeDeletionPath($path);
        $prefix = rtrim($basePath, '/') . '/';
        if ($path === $basePath || !str_starts_with($path, $prefix)) {
            throw new InvalidArgumentException('Only directories below the transcode storage root may be deleted.');
        }

        $root = realpath($basePath);
        if ($root === false || !is_dir($root)) {
            return;
        }

        // Resolve the trusted configured root once, then inspect every component
        // below it without following links (including links to another cache dir).
        $components = explode('/', substr($path, strlen($prefix)));
        $target = rtrim($root, '/');
        foreach ($components as $index => $component) {
            $target .= '/' . $component;
            clearstatcache(true, $target);
            if (is_link($target)) {
                if ($index !== count($components) - 1) {
                    throw new InvalidArgumentException('A deletion path must not traverse a symbolic link.');
                }
                self::removeEntry($target, false);

                return;
            }
            if (!is_dir($target)) {
                return;
            }
        }

        $iterator = new \RecursiveDirectoryIterator($target, \RecursiveDirectoryIterator::SKIP_DOTS);
        $entries = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            // getRealPath() would resolve a link and remove its target instead.
            self::removeEntry($entry->getPathname(), !$entry->isLink() && $entry->isDir());
        }
        self::removeEntry($target, true);
    }

    private static function normalizeDeletionPath(string $path): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0")
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) === 1) {
            throw new InvalidArgumentException('A deletion path must be absolute and contain no traversal components.');
        }

        return rtrim(preg_replace('~/+~', '/', $path) ?? $path, '/') ?: '/';
    }

    private static function removeEntry(string $path, bool $directory): void
    {
        $removed = $directory ? @rmdir($path) : @unlink($path);
        if (!$removed) {
            throw new RuntimeException('Unable to remove a transcode cache entry.');
        }
    }

    public function getDirectorySize(string $path): int
    {
        $path = $this->resolver->resolvePath($path);
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $it = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it);

        foreach ($files as $file) {
            if (!$file->isLink() && $file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    public function getBasePath(): string
    {
        return $this->resolver->getBasePath();
    }

    public function getVideoDirectories(): array
    {
        return $this->resolver->getVideoDirectories();
    }
}
