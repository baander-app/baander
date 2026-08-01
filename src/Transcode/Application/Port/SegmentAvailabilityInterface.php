<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Signals segment availability across Swoole workers without polling.
 *
 * The long-lived FFmpeg worker writes a row the instant a segment is produced;
 * the HTTP segment controller reads the row to decide whether to serve
 * immediately or fall back to the file-stat wait loop.
 *
 * $tierKey is either a QualityTier name (video) or a BCP-47 language code
 * (audio). The key namespace is "jobId:tierKey:index".
 */
interface SegmentAvailabilityInterface
{
    /**
     * Mark a segment as ready with its filesystem path.
     */
    public function markReady(Uuid $jobId, string $tierKey, int $segmentIndex, string $path): void;

    /**
     * Return the filesystem path if the segment is ready, or null.
     */
    public function isReady(Uuid $jobId, string $tierKey, int $segmentIndex): ?string;

    /**
     * Remove all rows for a job (on completion or before a kill+restart seek).
     * Safe to call on a job with no rows.
     */
    public function clearJob(Uuid $jobId): void;
}
