<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

/**
 * Result of a single cache-sweep run.
 *
 * Carries the deleted and retained cache directories plus aggregate size
 * metrics so both the console command (human-readable output / dry-run) and
 * the unit tests can assert on the outcome without scraping log lines.
 * A directory is named by its video ID, or `audio-renditions/<track>` for a
 * track's audio renditions.
 */
final class SweepTranscodeCacheResult
{
    /**
     * @param list<string> $deletedDirectories  cache directories actually deleted
     * @param list<string> $retainedDirectories cache directories kept (active or within TTL)
     * @param list<string> $skippedActive       cache directories kept because they are in active use
     */
    public function __construct(
        public readonly array $deletedDirectories,
        public readonly array $retainedDirectories,
        public readonly array $skippedActive,
        public readonly int $bytesFreed,
        public readonly int $totalCacheBytesBefore,
        public readonly int $totalCacheBytesAfter,
        public readonly bool $dryRun,
    ) {
    }

    public function deletedCount(): int
    {
        return count($this->deletedDirectories);
    }
}
