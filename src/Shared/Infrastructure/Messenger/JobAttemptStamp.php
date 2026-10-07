<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * The job monitor attempt that the current delivery started. Its completion updates the
 * job's row only while that attempt is current. A redelivery starts a new attempt, so the
 * stamp is never sent with the message.
 */
final readonly class JobAttemptStamp implements NonSendableStampInterface
{
    public function __construct(
        public int $attempt,
    ) {
    }
}
