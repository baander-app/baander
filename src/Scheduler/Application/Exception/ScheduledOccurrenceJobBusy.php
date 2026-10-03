<?php

declare(strict_types=1);

namespace App\Scheduler\Application\Exception;

use App\Shared\Domain\Model\Uuid;

/** Ordinary transport retry applies; an unresolved attempt has no automatic expiry. */
final class ScheduledOccurrenceJobBusy extends \RuntimeException
{
    public function __construct(public readonly Uuid $jobId)
    {
        parent::__construct(sprintf('Scheduled job %s has an unresolved wrapper invocation.', $jobId->toString()));
    }
}
