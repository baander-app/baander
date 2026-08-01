<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

/**
 * Result of a single cache-sweep run.
 *
 * Carries the deleted and retained video directories plus aggregate size
 * metrics so both the console command (human-readable output / dry-run) and
 * the unit tests can assert on the outcome without scraping log lines.
 */
final class SweepTranscodeCacheResult
{
    /**
     * @param list<string> $deletedVideoIds  video directory basenames actually deleted
     * @param list<string> $retainedVideoIds video directory basenames kept (active or within TTL)
     * @param list<string> $skippedActive     video directory basenames kept because the job/session is active
     */
    public function __construct(
        public readonly array $deletedVideoIds,
        public readonly array $retainedVideoIds,
        public readonly array $skippedActive,
        public readonly int $bytesFreed,
        public readonly int $totalCacheBytesBefore,
        public readonly int $totalCacheBytesAfter,
        public readonly bool $dryRun,
    ) {
    }

    public function deletedCount(): int
    {
        return count($this->deletedVideoIds);
    }
}
