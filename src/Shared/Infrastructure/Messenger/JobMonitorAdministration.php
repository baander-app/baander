<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\CancellableJobInterface;
use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\DTO\JobAnalyticsRange;
use App\Shared\Application\DTO\JobMonitorOverview;
use App\Shared\Application\DTO\JobMonitorPage;
use App\Shared\Application\DTO\JobMonitorPruneResult;
use App\Shared\Application\DTO\JobMonitorQuery;
use App\Shared\Application\DTO\JobMonitorRecord;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Domain\Model\JobStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Throwable;

/**
 * The job monitor over JobMonitorService, the Messenger bus and the Redis cancellation flag.
 */
final readonly class JobMonitorAdministration implements JobMonitorAdministrationInterface, CancellableJobInterface
{
    /** A cancellation request outlives any job run it can still reach. */
    private const int CANCEL_FLAG_TTL_SECONDS = 3600;

    public function __construct(
        private JobMonitorService $jobMonitorService,
        private CursorCodec $cursorCodec,
        private MessageBusInterface $messageBus,
        private JobMessageSerializer $messageSerializer,
        private RedisClientFactory $redisClientFactory,
        private LoggerInterface $logger,
        private SendersLocatorInterface $sendersLocator,
    ) {
    }

    public function overview(): JobMonitorOverview
    {
        return new JobMonitorOverview(
            $this->jobMonitorService->countByStatus(),
            array_map(self::record(...), $this->jobMonitorService->getRunning()),
        );
    }

    public function jobs(JobMonitorQuery $query): JobMonitorPage
    {
        if ($query->status !== null && JobStatus::tryFrom($query->status) === null) {
            $message = sprintf('status must be one of: %s.', implode(', ', array_map(
                static fn (JobStatus $status): string => $status->value,
                JobStatus::cases(),
            )));

            throw new InvalidInputException($message, ['status' => $message]);
        }

        $result = $this->jobMonitorService->findWithCursor(
            new JobMonitorFilter($query->status, $query->name, $query->queue),
            $query->cursor === null ? null : $this->cursorCodec->decode($query->cursor),
            max(1, min($query->limit, JobMonitorQuery::MAX_LIMIT)),
            $query->sort,
            $query->direction,
        );

        /** @var list<JobMonitorEntity> $items */
        $items = array_values($result->items);

        return new JobMonitorPage(
            array_map(self::record(...), $items),
            $result->nextCursor === null ? null : $this->cursorCodec->encode($result->nextCursor),
            $result->hasNextPage,
            $result->perPage,
        );
    }

    public function job(string $jobId): JobMonitorRecord
    {
        return self::record($this->entity($jobId));
    }

    public function retry(string $jobId, string $actor): string
    {
        $job = $this->entity($jobId);

        if ($job->getStatus() !== JobStatus::Failed) {
            throw new ConflictException('Only failed jobs can be retried.', ['reason' => 'not_failed']);
        }

        if ($job->isRetried()) {
            throw new ConflictException('This job has already been retried.', ['reason' => 'already_retried']);
        }

        if ($job->getData() === null) {
            throw new ConflictException('No message payload stored for this job.', ['reason' => 'no_payload']);
        }

        $message = $this->messageSerializer->deserialize($job->getData());
        if ($message === null) {
            throw new ConflictException('The stored message payload cannot be read.', ['reason' => 'unreadable_payload']);
        }

        // The record read above can be stale; the claim decides between concurrent retries.
        if (!$this->jobMonitorService->claimRetry($jobId)) {
            throw new ConflictException('This job was retried or restarted meanwhile.', ['reason' => 'already_retried']);
        }

        $newJobId = new PublicId();
        $envelope = new Envelope($message, [new JobIdStamp($newJobId)]);
        if ($job->getQueue() !== null) {
            $envelope = $envelope->with(new TransportNamesStamp([$job->getQueue()]));
        }

        try {
            $this->messageBus->dispatch($envelope);
        } catch (Throwable $exception) {
            $this->jobMonitorService->releaseRetry($jobId);

            throw $exception;
        }

        $this->jobMonitorService->markRetriedWithAudit($jobId, $newJobId->toString(), $actor);

        return $newJobId->toString();
    }

    public function cancel(string $jobId): void
    {
        $job = $this->entity($jobId);

        if ($job->getStatus() === JobStatus::Finished) {
            throw new ConflictException('Finished jobs cannot be cancelled.', ['reason' => 'finished']);
        }

        if ($job->getStatus() === JobStatus::Failed) {
            throw new ConflictException('Failed jobs cannot be cancelled.', ['reason' => 'failed']);
        }

        $this->redisClientFactory->borrow(
            static fn (\Redis $redis): mixed => $redis->setex(self::cancelFlag($jobId), self::CANCEL_FLAG_TTL_SECONDS, '1'),
        );
    }

    public function checkCancellation(string $jobId): void
    {
        $flagged = $this->redisClientFactory->borrow(
            static fn (\Redis $redis): mixed => $redis->exists(self::cancelFlag($jobId)),
        );

        if ($flagged !== false && (int) $flagged > 0) {
            throw JobCancelledException::forJob($jobId);
        }
    }

    public function prune(int $days, bool $dryRun = false): JobMonitorPruneResult
    {
        if ($days < 1) {
            throw new InvalidInputException('Days must be at least 1.', ['days' => 'Days must be at least 1.']);
        }

        $olderThan = new \DateTimeImmutable(sprintf('-%d days', $days));

        return new JobMonitorPruneResult(
            $dryRun ? $this->jobMonitorService->countPrunable($olderThan) : $this->jobMonitorService->prune($olderThan),
            $olderThan,
        );
    }

    public function analyticsSummary(JobAnalyticsRange $range): array
    {
        return $this->jobMonitorService->getAnalyticsSummary($range->from, $range->to);
    }

    public function analyticsTiming(JobAnalyticsRange $range): array
    {
        return $this->jobMonitorService->getAnalyticsTiming($range->from, $range->to);
    }

    public function analyticsFailures(JobAnalyticsRange $range, int $limit = 50): array
    {
        return $this->jobMonitorService->getAnalyticsFailures($range->from, $range->to, max(1, min($limit, 200)));
    }

    public function runInline(object $message): InlineJobRun
    {
        $jobId = (new PublicId())->toString();
        // A received envelope is handled here instead of being sent. It is marked as received
        // from the transport the message is routed to, so handlers bound to that transport
        // (fromTransport) run as they would in a worker; an unrouted message is 'sync'.
        $envelope = new Envelope($message, [
            new JobIdStamp(PublicId::fromString($jobId)),
            new ReceivedStamp($this->routedTransport($message)),
        ]);
        $serialized = $this->messageSerializer->serialize($envelope);
        $attempt = $this->jobMonitorService->startAttempt(
            jobId: $jobId,
            name: (new \ReflectionClass($message))->getShortName(),
            queue: null,
            data: $serialized,
            dataTruncated: $serialized === null,
        );

        try {
            $handled = $this->messageBus->dispatch($envelope);
        } catch (Throwable $exception) {
            $cause = self::cause($exception);
            try {
                $this->jobMonitorService->markFailed($jobId, $attempt, $cause);
            } catch (Throwable $monitorException) {
                $this->logger->error('Failed to mark inline job as failed in monitor', [
                    'job_id' => $jobId,
                    'monitor_error' => $monitorException->getMessage(),
                ]);
            }

            throw $cause;
        }

        // JobCancellationMiddleware has already recorded the cancellation.
        if ($handled->last(JobCancelledStamp::class) !== null) {
            throw JobCancelledException::forJob($jobId);
        }

        $this->jobMonitorService->markFinished($jobId, $attempt);

        return new InlineJobRun($jobId, $handled->last(HandledStamp::class)?->getResult());
    }

    /** The first transport the message is routed to, or 'sync' when it is handled synchronously. */
    private function routedTransport(object $message): string
    {
        foreach ($this->sendersLocator->getSenders(new Envelope($message)) as $transport => $sender) {
            return (string) $transport;
        }

        return 'sync';
    }

    /** @throws NotFoundException */
    private function entity(string $jobId): JobMonitorEntity
    {
        return $this->jobMonitorService->findByJobId($jobId)
            ?? throw new NotFoundException('Job not found.', ['jobId' => $jobId]);
    }

    private static function cancelFlag(string $jobId): string
    {
        return sprintf('job_cancel:%s', $jobId);
    }

    /** Unwraps HandlerFailedException, nested ones included, when it wraps a single exception. */
    private static function cause(Throwable $exception): Throwable
    {
        while ($exception instanceof HandlerFailedException && count($exception->getWrappedExceptions()) === 1) {
            [$exception] = array_values($exception->getWrappedExceptions());
        }

        return $exception;
    }

    private static function record(JobMonitorEntity $job): JobMonitorRecord
    {
        return new JobMonitorRecord(
            jobId: $job->getJobId(),
            name: $job->getName(),
            queue: $job->getQueue(),
            status: $job->getStatus(),
            progress: $job->getProgress(),
            attempt: $job->getAttempt(),
            retried: $job->isRetried(),
            startedAt: $job->getStartedAt(),
            finishedAt: $job->getFinishedAt(),
            createdAt: $job->getCreatedAt(),
            updatedAt: $job->getUpdatedAt(),
            exceptionClass: $job->getExceptionClass(),
            exception: $job->getException(),
            data: $job->getData(),
            dataTruncated: $job->getDataTruncated(),
            durationMicroseconds: $job->getDurationMicroseconds(),
        );
    }
}
