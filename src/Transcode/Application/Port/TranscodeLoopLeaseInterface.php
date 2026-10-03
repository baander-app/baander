<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;

/** A process-local ownership handle for one acquisition of a job's encoding loop. */
interface TranscodeLoopLeaseInterface
{
    public function getJobId(): Uuid;

    /** False means ownership is lost; this handle can never renew again. */
    public function renew(int $ttlSeconds): bool;

    /** Idempotently release only this acquisition, without affecting a successor. */
    public function release(): void;
}
