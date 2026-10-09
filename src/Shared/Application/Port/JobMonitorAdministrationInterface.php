<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Application\DTO\InlineJobRun;
use App\Shared\Application\DTO\JobCancellation;
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

/**
 * Reads and operates the background job monitor. The admin monitor pages and the
 * `app:monitor:*` commands both go through it, so they apply the same rules.
 */
interface JobMonitorAdministrationInterface
{
    public function overview(): JobMonitorOverview;

    /** @throws InvalidInputException for an unknown status filter */
    public function jobs(JobMonitorQuery $query): JobMonitorPage;

    /**
     * A job with its error, stored message and whether it can be cancelled.
     *
     * @throws NotFoundException
     */
    public function job(string $jobId): JobMonitorRecord;

    /**
     * Dispatches a failed job's stored message again under a new job ID, to the queue it
     * was received from, and records the retry with the actor in the job's audit log. A job
     * is retried at most once.
     *
     * @param string $actor the admin's user identifier, or Actor::CLI
     *
     * @return string the new job ID
     *
     * @throws NotFoundException
     * @throws ConflictException when the job has not failed, was already retried, or has no readable stored message
     */
    public function retry(string $jobId, string $actor): string;

    /**
     * Requests cooperative cancellation: sets the flag that a running job reads at its next
     * cancellation checkpoint (JobCancellationCheckpointInterface), where it stops and its
     * record becomes cancelled. A job without checkpoints runs to its end. Cancelling a job
     * again sets the flag again.
     *
     * A finished job that queued work to run later, such as a lyrics bulk fetch, has that
     * work stopped instead (QueuedJobWorkInterface): what has not run yet is skipped when it
     * comes due, and the job's record becomes cancelled.
     *
     * @throws NotFoundException
     * @throws ConflictException when the job has failed, or has finished without queued work waiting
     */
    public function cancel(string $jobId): JobCancellation;

    /**
     * Deletes the finished, failed and cancelled jobs created more than the given number of days ago.
     *
     * @param bool $dryRun count the jobs instead of deleting them
     *
     * @throws InvalidInputException when days is less than 1
     */
    public function prune(int $days, bool $dryRun = false): JobMonitorPruneResult;

    /**
     * @return array{
     *     statusCounts: array<string, int>,
     *     jobTypeBreakdown: array<int, array{name: string, count: int}>,
     *     successRate: float,
     *     throughputPerHour: float,
     * }
     */
    public function analyticsSummary(JobAnalyticsRange $range): array;

    /**
     * @return array{
     *     executionTimes: array<int, array{name: string, avg: float, median: float, p95: float}>,
     *     queueLatency: array<int, array{name: string, avg: float}>,
     * }
     */
    public function analyticsTiming(JobAnalyticsRange $range): array;

    /**
     * @param int $limit recent failures to return, clamped to 1-200
     *
     * @return array{
     *     topFailingTypes: array<int, array{name: string, count: int}>,
     *     topExceptionClasses: array<int, array{class: string, count: int}>,
     *     retryFrequency: array{retried: int, total: int, rate: float},
     *     recentFailures: array<int, array{jobId: string, name: string|null, exceptionClass: string|null, exceptionMessage: string|null, failedAt: string|null}>,
     * }
     */
    public function analyticsFailures(JobAnalyticsRange $range, int $limit = 50): array;

    /**
     * Handles a message in this process, as Messenger's sync transport does, and records the
     * run in the job monitor like a queued job: a running record under a new job ID with the
     * serialized message, then finished, or failed with the error, or cancelled when the job
     * was cancelled and stopped at a checkpoint. The record has no queue, so retrying it from
     * the monitor dispatches the message through its normal routing.
     *
     * Console commands use it to run long work inline.
     *
     * @throws JobCancelledException when the job was cancelled and stopped at a checkpoint
     * @throws \Throwable the handler's own exception, unwrapped from HandlerFailedException
     */
    public function runInline(object $message): InlineJobRun;
}
