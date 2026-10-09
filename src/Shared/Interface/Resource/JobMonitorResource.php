<?php

declare(strict_types=1);

namespace App\Shared\Interface\Resource;

use App\Shared\Application\DTO\JobMonitorOverview;
use App\Shared\Application\DTO\JobMonitorPage;
use App\Shared\Application\DTO\JobMonitorRecord;
use App\Shared\Domain\Model\JobStatus;

/**
 * The job monitor's API payloads. The `app:monitor:*` commands print the same arrays with `--json`.
 */
final class JobMonitorResource extends AbstractResource
{
    /** A job as the job list shows it. */
    public static function from(mixed $source): array
    {
        assert($source instanceof JobMonitorRecord);

        $failed = $source->status === JobStatus::Failed;

        return [
            'jobId' => $source->jobId,
            'name' => $source->name,
            'queue' => $source->queue,
            'status' => $source->status->value,
            'progress' => $source->progress,
            'attempt' => $source->attempt,
            'retried' => $source->retried,
            'startedAt' => $source->startedAt?->format(\DateTimeInterface::ATOM),
            'finishedAt' => $source->finishedAt?->format(\DateTimeInterface::ATOM),
            'createdAt' => $source->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $source->updatedAt->format(\DateTimeInterface::ATOM),
            'exceptionClass' => $failed ? $source->exceptionClass : null,
        ];
    }

    /**
     * A job with its error, stored message, run time and whether it can be cancelled.
     *
     * @return array<string, mixed>
     */
    public static function detail(JobMonitorRecord $job): array
    {
        $failed = $job->status === JobStatus::Failed;

        return self::from($job) + [
            'exception' => $failed ? $job->exception : null,
            'data' => $job->data,
            'dataTruncated' => $job->dataTruncated,
            'duration' => $job->durationMicroseconds === null ? null : $job->durationMicroseconds / 1e6,
            'cancellable' => $job->cancellable === true,
        ];
    }

    /** @return array{counts: array<string, int>, running: list<array<string, mixed>>} */
    public static function overview(JobMonitorOverview $overview): array
    {
        return [
            'counts' => $overview->counts,
            'running' => array_map(
                static fn (JobMonitorRecord $job): array => [
                    'jobId' => $job->jobId,
                    'name' => $job->name,
                    'queue' => $job->queue,
                    'startedAt' => $job->startedAt?->format(\DateTimeInterface::ATOM),
                    'progress' => $job->progress,
                ],
                $overview->running,
            ),
        ];
    }

    /** @return array{items: array<int, array<string, mixed>>, next_cursor: string|null, has_next_page: bool, per_page: int} */
    public static function page(JobMonitorPage $page): array
    {
        return [
            'items' => self::collection($page->items),
            'next_cursor' => $page->nextCursor,
            'has_next_page' => $page->hasNextPage,
            'per_page' => $page->perPage,
        ];
    }
}
