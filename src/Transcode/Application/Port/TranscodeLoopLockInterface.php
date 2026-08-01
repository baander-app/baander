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
     * @return bool true if the lock was acquired by this caller
     */
    public function acquire(Uuid $jobId, int $ttlSeconds): bool;

    /**
     * Renew an already-held lock.
     *
     * @return bool true if the lock existed and was renewed by this caller
     */
    public function renew(Uuid $jobId, int $ttlSeconds): bool;

    /**
     * Release the loop lock.
     *
     * Safe to call even if the lock is not held; will only delete the key when
     * the value matches the current owner.
     */
    public function release(Uuid $jobId): void;
}
