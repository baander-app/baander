<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\PublicId;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Gives every dispatched message its job ID before a transport stores it, so all of its
 * deliveries share one job_monitors row. A worker that later receives the message records
 * the row; see WorkerJobMonitorSubscriber and SwooleTaskJobMonitorDecorator.
 */
final readonly class JobMonitoringMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) === null && $envelope->last(JobIdStamp::class) === null) {
            $envelope = $envelope->with(new JobIdStamp(new PublicId()));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
