<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Marks a delivery whose handler stopped at a cancellation checkpoint. JobCancellationMiddleware
 * adds it to the envelope it returns in place of the handler's exception; an inline run reads it
 * to report the cancellation.
 */
final readonly class JobCancelledStamp implements NonSendableStampInterface
{
}
