<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\PublicId;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Identifies one job in the job monitor. The stamp is sent with the message, so every
 * delivery of the message (a Messenger retry, a retry from the failure transport, or a
 * redelivery after a worker died) updates the same job_monitors row.
 */
final readonly class JobIdStamp implements StampInterface
{
    public function __construct(
        public PublicId $jobId,
    ) {
    }
}
