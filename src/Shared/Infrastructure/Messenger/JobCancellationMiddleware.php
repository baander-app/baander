<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\JobCancelledException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;

/**
 * Runs each received job as the current job of its coroutine, so cancellation checkpoints in
 * its handlers find the job ID, and ends a job whose handler stopped at a checkpoint.
 *
 * Every execution path passes a received envelope with a job ID through the bus: a Messenger
 * worker, a Swoole task worker and an inline run. A handler that throws JobCancelledException
 * leaves the job's monitor row `cancelled`, and the delivery returns normally with a
 * JobCancelledStamp: the transport acknowledges it, so it is not retried and does not reach
 * the failure transport. The recorders' later finish of the attempt leaves the cancelled row
 * as it is (JobMonitorService only completes a running attempt).
 */
final readonly class JobCancellationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JobExecutionContext $context,
        private JobMonitorService $jobMonitorService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $jobId = $envelope->last(JobIdStamp::class)?->jobId->toString();
        if ($jobId === null || $envelope->last(ReceivedStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        try {
            return $this->context->run($jobId, static fn (): Envelope => $stack->next()->handle($envelope, $stack));
        } catch (Throwable $exception) {
            if (!self::isCancellation($exception)) {
                throw $exception;
            }
        }

        $this->jobMonitorService->markCancelled($jobId);
        $this->logger->info('Job cancelled', [
            'job_id' => $jobId,
            'job_type' => $envelope->getMessage()::class,
        ]);

        return $envelope->with(new JobCancelledStamp());
    }

    /** Whether the handlers stopped only because the job was cancelled. */
    private static function isCancellation(Throwable $exception): bool
    {
        if ($exception instanceof JobCancelledException) {
            return true;
        }

        if (!$exception instanceof HandlerFailedException) {
            return false;
        }

        $wrapped = $exception->getWrappedExceptions();

        return $wrapped !== [] && array_all($wrapped, self::isCancellation(...));
    }
}
