<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Storage;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\MediaTrack;
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

    public function forGeneration(Uuid $videoId, QualityTier|string $qualityTier, int $generation, Uuid $jobId): self
    {
        return new self($this->resolver->forGeneration($videoId, $qualityTier, $generation, $jobId));
    }

    public function resolveJobDirectory(Uuid $videoId, QualityTier|string $qualityTier): string
    {
        return $this->resolver->resolveJobDirectory($videoId, $qualityTier);
    }

    public function resolveInitSegmentPath(Uuid $videoId, QualityTier|string $qualityTier): string
    {
        return $this->resolver->resolveInitSegmentPath($videoId, $qualityTier);
    }

    public function resolveSegmentPath(Uuid $videoId, QualityTier|string $qualityTier, int $segmentIndex): string
    {
        return $this->resolver->resolveSegmentPath($videoId, $qualityTier, $segmentIndex);
    }

    public function resolveNativeManifestPath(Uuid $videoId, QualityTier|string $qualityTier): string
    {
        return $this->resolver->resolveNativeManifestPath($videoId, $qualityTier);
    }

    public function resolveTrackInitSegmentPath(Uuid $videoId, QualityTier|string $qualityTier, MediaTrack $track): string
    {
        return $this->resolver->resolveTrackInitSegmentPath($videoId, $qualityTier, $track);
    }

    public function resolveTrackSegmentPath(Uuid $videoId, QualityTier|string $qualityTier, MediaTrack $track, int $nativeNumber): string
    {
        return $this->resolver->resolveTrackSegmentPath($videoId, $qualityTier, $track, $nativeNumber);
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

    public function retainUntil(string $directory, int $expiresAt): bool
    {
        if ($expiresAt < 0) {
            throw new InvalidArgumentException('A retention deadline must be non-negative.');
        }

        return $this->withRetentionLock($directory, function (int $current, string $lease) use ($expiresAt): bool {
            if ($expiresAt > $current) {
                $temporary = $lease . '.' . bin2hex(random_bytes(8)) . '.tmp';
                $handle = @fopen($temporary, 'x+b');
                if ($handle === false) {
                    throw new RuntimeException('Unable to create a cache retention record.');
                }
                try {
                    $content = $expiresAt . "\n";
                    if (fwrite($handle, $content) !== strlen($content) || !fflush($handle) || !fsync($handle)) {
                        throw new RuntimeException('Unable to persist a cache retention record.');
                    }
                    if (!rename($temporary, $lease)) {
                        throw new RuntimeException('Unable to publish a cache retention record.');
                    }
                } finally {
                    fclose($handle);
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }

            return true;
        }, false);
    }

    public function hasUnexpiredRetention(string $directory): bool
    {
        return $this->withRetentionLock($directory, static fn (int $current): bool => $current >= time(), false, LOCK_SH);
    }

    public function deleteIfUnretained(string $directory): bool
    {
        return $this->withRetentionLock($directory, function (int $current) use ($directory): bool {
            if ($current >= time()) {
                return false;
            }
            $this->deleteDirectory($directory);

            return true;
        }, true);
    }

    /** @param callable(int, string): bool $operation */
    private function withRetentionLock(string $directory, callable $operation, bool $missing, int $lockMode = LOCK_EX): bool
    {
        $this->resolver->assertGenerationPath($directory);
        $base = self::normalizeDeletionPath($this->resolver->getBasePath());
        $directory = self::normalizeDeletionPath($directory);
        $prefix = rtrim($base, '/') . '/';
        if ($directory === $base || !str_starts_with($directory, $prefix)) {
            throw new InvalidArgumentException('Retention requires a directory below the transcode storage root.');
        }
        $root = realpath($base);
        if ($root === false || !is_dir($root)) {
            return $missing;
        }
        $components = explode('/', substr($directory, strlen($prefix)));
        if (!self::retentionDirectoryExists($root, $components)) {
            return $missing;
        }
        $scope = $components[0] === SegmentFileResolver::AUDIO_RENDITION_DIRECTORY
            ? implode('/', array_slice($components, 0, 2)) : $components[0];
        $record = $root . '/.retention-' . hash('sha256', $scope);
        $lockPath = $record . '.lock';
        if (is_link($lockPath)) {
            throw new InvalidArgumentException('A retention lock must not be a symbolic link.');
        }
        $lock = @fopen($lockPath, 'x+b');
        if ($lock === false) {
            $lock = @fopen($lockPath, 'r+b');
        }
        if ($lock === false) {
            throw new RuntimeException('Unable to open a cache retention lock.');
        }
        try {
            if (!flock($lock, $lockMode)) {
                throw new RuntimeException('Unable to lock cache retention.');
            }
            clearstatcache(true, $lockPath);
            $entry = lstat($lockPath);
            $opened = fstat($lock);
            if ($entry === false || $opened === false || ($entry['mode'] & 0170000) !== 0100000
                || $entry['ino'] !== $opened['ino'] || $entry['dev'] !== $opened['dev']) {
                throw new InvalidArgumentException('A retention lock must remain a regular file.');
            }
            if (!self::retentionDirectoryExists($root, $components)) {
                return $missing;
            }
            $lease = $record . '.lease';
            clearstatcache(true, $lease);
            if (is_link($lease) || (file_exists($lease) && !is_file($lease))) {
                throw new InvalidArgumentException('A retention record must be a regular file.');
            }
            $content = is_file($lease) ? file_get_contents($lease) : '';
            if ($content === false || ($content !== '' && preg_match('/^[0-9]+\n?$/D', $content) !== 1)) {
                throw new RuntimeException('The cache retention record is invalid.');
            }

            return $operation($content === '' ? 0 : (int) trim($content), $lease);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param list<string> $components
     * @phpstan-impure
     */
    private static function retentionDirectoryExists(string $root, array $components): bool
    {
        $path = $root;
        foreach ($components as $component) {
            $path .= '/' . $component;
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new InvalidArgumentException('A retention path must not traverse a symbolic link.');
            }
            if (!is_dir($path)) {
                return false;
            }
        }

        return true;
    }

    public function deleteDirectory(string $path): void
    {
        $this->resolver->assertGenerationPath($path);
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

    public function getAudioRenditionDirectories(): array
    {
        return $this->resolver->getAudioRenditionDirectories();
    }

    public function resolveAudioRenditionDirectory(string $trackKey): string
    {
        return $this->resolver->resolveAudioRenditionDirectory($trackKey);
    }

    public function isAudioRenditionEncodingSince(string $trackKey, \DateTimeImmutable $since): bool
    {
        $directory = $this->resolver->resolveAudioRenditionDirectory($trackKey);
        if (is_link($directory) || !is_dir($directory)) {
            return false;
        }

        foreach (scandir($directory) ?: [] as $signature) {
            $rendition = $directory . '/' . $signature;
            if (preg_match('/^(opus|aac|mp3)-[0-9]+k-[a-f0-9]{16}\\.\\1$/D', $signature) !== 1
                || is_link($rendition) || !is_dir($rendition)
            ) {
                continue;
            }
            foreach (scandir($rendition) ?: [] as $generation) {
                $attempt = $rendition . '/' . $generation;
                if (preg_match('/^generation-[1-9][0-9]*$/D', $generation) !== 1 || is_link($attempt) || !is_dir($attempt)) {
                    continue;
                }
                $partial = $attempt . '/' . $signature . SegmentFileResolver::PARTIAL_SUFFIX;
                clearstatcache(true, $partial);
                if (is_link($partial) || !is_file($partial)) {
                    continue;
                }
                $modified = @filemtime($partial);
                if ($modified !== false && $modified >= $since->getTimestamp()) {
                    return true;
                }
            }
        }

        return false;
    }
}
