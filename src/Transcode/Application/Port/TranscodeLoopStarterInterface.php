<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;

/** Local runtime handoff; leases must never enter serialized messages. */
interface TranscodeLoopStarterInterface
{
    /**
     * Accept a session and its acquired lease for execution.
     * On return the runtime owns cleanup; on rejection the caller still owns it.
     */
    public function start(Uuid $sessionId, TranscodeLoopLeaseInterface $lease): void;
}
