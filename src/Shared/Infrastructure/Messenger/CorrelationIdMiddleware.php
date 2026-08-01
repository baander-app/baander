<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Infrastructure\Logging\CorrelationIdProcessor;
use App\Shared\Infrastructure\Messenger\Stamp\CorrelationIdStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Propagates the correlation ID across the Messenger transport boundary.
 *
 * Outgoing: stamps the envelope with the current correlation ID from the processor.
 * Incoming: restores the correlation ID into the processor so task-worker logs
 *           carry the same ID as the originating HTTP request.
 */
final readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CorrelationIdProcessor $processor,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $existingStamp = $envelope->last(CorrelationIdStamp::class);

        if ($existingStamp !== null) {
            // Incoming message in a task worker — restore the correlation ID
            $this->processor->setCorrelationId($existingStamp->correlationId);
        } else {
            // Outgoing message — stamp with the current correlation ID
            $correlationId = $this->processor->getCurrentCorrelationId();

            if ($correlationId !== null) {
                $envelope = $envelope->with(new CorrelationIdStamp($correlationId));
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
