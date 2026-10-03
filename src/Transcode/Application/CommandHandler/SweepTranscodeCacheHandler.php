<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sweeps the transcode segment cache.
 *
 * Segments are now a persistent cache (seek-back hits cached files rather than
 * re-transcoding). Without cleanup the cache grows unbounded. This handler
 * implements two eviction policies, whichever triggers first:
 *
 *  - **TTL:** delete a video's entire cache directory when its newest file is
 *    older than the configured TTL (R1). Eviction granularity is the whole
 *    video directory, not individual segments — a video's segments across
 *    tiers form an atomic cache unit (KTD-1).
 *  - **LRU size budget:** when the total cache exceeds the size budget, evict
 *    the least-recently-accessed video directories first (by newest-file
 *    mtime) until under budget (R2).
 *
 * Safety (R4): a video directory is never swept while it is in active use.
 * "In use" means either (a) the video has a job that is still pending or
 * in-progress, or (b) a viewer has a session that was updated within the
 * active-playback window (default 30 min — a recently-touched session means
 * someone is watching right now).
 */
final class SweepTranscodeCacheHandler
{
    /** Default TTL before an idle cache directory is swept (hours). */
    public const int DEFAULT_TTL_HOURS = 24;

    /** Default cache size budget in gigabytes. */
    public const float DEFAULT_MAX_GB = 50.0;

    /** Window during which a session update counts as "viewer actively watching" (seconds). */
    public const int DEFAULT_ACTIVE_WINDOW_SECONDS = 1800;

    public function __construct(
        private readonly TranscodeStoragePortInterface $storage,
        private readonly TranscodeJobPortInterface $jobPort,
        private readonly TranscodeSessionPortInterface $sessionPort,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array{ttl_hours?: int, max_gb?: float, dry_run?: bool, active_window_seconds?: int} $options
     */
    public function sweep(array $options = []): SweepTranscodeCacheResult
    {
        $ttlHours = $options['ttl_hours'] ?? self::DEFAULT_TTL_HOURS;
        $maxGb = $options['max_gb'] ?? self::DEFAULT_MAX_GB;
        $dryRun = $options['dry_run'] ?? false;
        $activeWindow = $options['active_window_seconds'] ?? self::DEFAULT_ACTIVE_WINDOW_SECONDS;

        $maxBytes = (int) round($maxGb * 1024 * 1024 * 1024);
        $ttlCutoff = (new DateTimeImmutable())->modify(sprintf('-%d hours', $ttlHours));
        $activeCutoff = (new DateTimeImmutable())->modify(sprintf('-%d seconds', $activeWindow));

        $videoIds = $this->storage->getVideoDirectories();

        $deleted = [];
        $retained = [];
        $skippedActive = [];
        $bytesFreed = 0;

        $dirSize = [];
        $dirNewestMtime = [];
        $totalBytes = 0;

        // First pass: classify each video directory.
        foreach ($videoIds as $videoId) {
            $dir = $this->storage->getBasePath() . '/' . $videoId;
            $size = $this->storage->getDirectorySize($dir);
            $dirSize[$videoId] = $size;
            $totalBytes += $size;
            $dirNewestMtime[$videoId] = $this->newestFileMtime($dir);

            // R4 — never sweep active content.
            if ($this->isVideoActive($videoId, $activeCutoff)) {
                $skippedActive[] = $videoId;
                $retained[] = $videoId;
                $this->logger->debug('Cache sweep: skipping active video {video}.', ['video' => $videoId]);
                continue;
            }

            // R1 — TTL: delete if the newest file is older than the TTL cutoff.
            $newestMtime = $dirNewestMtime[$videoId];
            if ($newestMtime !== null && (new DateTimeImmutable('@' . $newestMtime)) < $ttlCutoff) {
                $this->deleteVideoDir($videoId, $dir, $size, $dryRun, $deleted, $bytesFreed);
                $totalBytes -= $size;
                continue;
            }
        }

        $alreadyDeleted = array_flip($deleted);
        $alreadySkipped = array_flip($skippedActive);

        // R2 — LRU size budget: if still over budget, evict oldest-accessed
        // non-active directories (by newest-file mtime) until under budget.
        if ($totalBytes > $maxBytes) {
            $evictionCandidates = [];
            foreach ($videoIds as $videoId) {
                if (isset($alreadyDeleted[$videoId]) || isset($alreadySkipped[$videoId])) {
                    continue;
                }
                $evictionCandidates[] = $videoId;
            }

            usort(
                $evictionCandidates,
                static fn (string $a, string $b) => ($dirNewestMtime[$a] ?? 0) <=> ($dirNewestMtime[$b] ?? 0),
            );

            foreach ($evictionCandidates as $videoId) {
                if ($totalBytes <= $maxBytes) {
                    break;
                }
                $size = $dirSize[$videoId];
                $dir = $this->storage->getBasePath() . '/' . $videoId;
                $this->deleteVideoDir($videoId, $dir, $size, $dryRun, $deleted, $bytesFreed);
                $totalBytes -= $size;
            }
        }

        foreach ($videoIds as $videoId) {
            if (!in_array($videoId, $deleted, true)) {
                if (!in_array($videoId, $retained, true)) {
                    $retained[] = $videoId;
                }
            }
        }

        return new SweepTranscodeCacheResult(
            deletedVideoIds: $deleted,
            retainedVideoIds: $retained,
            skippedActive: $skippedActive,
            bytesFreed: $bytesFreed,
            totalCacheBytesBefore: array_sum($dirSize),
            totalCacheBytesAfter: $totalBytes,
            dryRun: $dryRun,
        );
    }

    /**
     * Has the video got a job that is still encoding, or a session touched
     * within the active-playback window?
     */
    private function isVideoActive(string $videoId, DateTimeImmutable $activeCutoff): bool
    {
        try {
            $uuid = Uuid::fromString($videoId);
        } catch (\Throwable) {
            // Not a UUID-shaped directory — leave it alone, treat as retained.
            return true;
        }

        foreach ($this->jobPort->findActiveByVideo($uuid) as $job) {
            $status = $job->getStatus();
            // (a) a job still encoding is always active.
            if ($status === TranscodeStatus::Pending || $status === TranscodeStatus::InProgress) {
                return true;
            }

            // (b) a session updated within the active window means someone is watching.
            foreach ($this->sessionPort->findByJob($job->getId()) as $session) {
                if ($session->getUpdatedAt() >= $activeCutoff) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Newest file mtime in a directory tree, or null when the tree is empty.
     */
    private function newestFileMtime(string $dir): ?int
    {
        if (!is_dir($dir)) {
            return null;
        }

        $newest = null;
        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it);

        foreach ($files as $file) {
            if (!$file->isLink() && $file->isFile()) {
                $mtime = $file->getMTime();
                if ($newest === null || $mtime > $newest) {
                    $newest = $mtime;
                }
            }
        }

        return $newest;
    }

    /** @param list<string> $deleted */
    private function deleteVideoDir(
        string $videoId,
        string $dir,
        int $size,
        bool $dryRun,
        array &$deleted,
        int &$bytesFreed,
    ): void {
        if (!$dryRun) {
            $this->storage->deleteDirectory($dir);
        }
        $deleted[] = $videoId;
        $bytesFreed += $size;
        $this->logger->debug('Cache sweep: {action} video {video} ({bytes} bytes).', [
            'action' => $dryRun ? 'would delete' : 'deleted',
            'video' => $videoId,
            'bytes' => $size,
        ]);
    }
}
