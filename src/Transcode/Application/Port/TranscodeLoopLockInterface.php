<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Distributed lock used to ensure only one Swoole worker runs the encoding
 * loop for a given transcode job at a time.
 *
 * Implementations are expected to be process-safe and worker-safe (e.g. Redis).
 */
interface TranscodeLoopLockInterface
{
    /**
     * Try to acquire the loop lock for the given job.
     *
     * @param Uuid $jobId Job identifier
     * @param int $ttlSeconds Lock time-to-live; must be renewed while the loop runs
     *
     * @return TranscodeLoopLeaseInterface|null A handle for this acquisition, or null when unavailable
     */
    public function acquire(Uuid $jobId, int $ttlSeconds): ?TranscodeLoopLeaseInterface;
}
