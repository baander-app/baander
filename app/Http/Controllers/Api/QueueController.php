<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\QueueMetrics\{MetricsRequest, ShowQueueMetricsRequest};
use App\Http\Requests\QueueMonitor\RetryJobRequest;
use App\Http\Resources\QueueMonitor\QueueMonitorResource;
use App\Models\QueueMonitor;
use App\Models\TokenAbility;
use App\Modules\Eloquent\BaseBuilder;
use App\Modules\Http\Resources\Json\JsonAnonymousResourceCollection;
use App\Modules\Queue\QueueMetrics\QueueMetricsService;
use App\Modules\Queue\QueueMonitor\MonitorStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Post, Prefix};
use Throwable;

/**
 * Queue monitoring and management controller
 *
 * Provides comprehensive queue system monitoring including job tracking, metrics collection,
 * failure analysis, and administrative operations. Supports job retry functionality and
 * queue health monitoring for system maintenance.
 */
#[Prefix('/queue-metrics')]
#[Middleware([
    'auth:sanctum',
    'ability:' . TokenAbility::ACCESS_API->value,
    'force.json',
])]
class QueueController extends Controller
{
    public function __construct(private readonly QueueMetricsService $metricsService)
    {
    }

    /**
     * Get paginated collection of queue monitor entries
     *
     * Returns filtered and paginated queue job monitoring data with support for
     * filtering by status, queue name, job name, and custom ordering options.
     * Provides comprehensive job execution tracking and debugging information.
     *
     * @param ShowQueueMetricsRequest $request Request with filtering and pagination parameters
     *
     * @throws AuthorizationException When user lacks dashboard access
     * @response JsonAnonymousResourceCollection<JsonPaginator<QueueMonitorResource>>
     */
    #[Get('/', 'api.queue-metrics.show')]
    public function show(ShowQueueMetricsRequest $request): JsonAnonymousResourceCollection
    {
        $this->gateCheckViewDashboard();

        /** @var bool|null $queuedFirst Whether to prioritize queued jobs in ordering */
        $queuedFirst = $request->query('queuedFirst');

        /** @var string|null $status Filter by job status (failed, succeeded, running, etc.) */
        $status = $request->query('status');

        /** @var string|null $queue Filter by specific queue name */
        $queue = $request->query('queue');

        /** @var string|null $name Filter by job name pattern */
        $name = $request->query('name');

        $models = QueueMonitor::query()
            ->when($status, function (BaseBuilder $query, $status) {
                return $query->where('status', $status);
            })
            ->when($queue, function (BaseBuilder $query, $queue) {
                return $query->where('queue', $queue);
            })
            ->when($name, function (BaseBuilder $query, $name) {
                return $query->where('name', 'like', '%' . $name . '%');
            })
            ->when($queuedFirst, function (BaseBuilder $query) {
                // Order by queued jobs first, then by start time
                return $query->orderByRaw('started_at DESC NULLS LAST');
            }, function (BaseBuilder $query) {
                // Default ordering by creation time
                return $query->orderBy('created_at', 'desc');
            })
            ->with(['failedJob']) // Load related failed job information
            ->paginate();

        // Paginated collection of queue monitor entries with filtering.
        return QueueMonitorResource::collection($models);
    }

    /**
     * Get list of all available queue names
     *
     * Returns a distinct list of all queue names currently in the monitoring system.
     * Useful for populating filter dropdowns and understanding queue structure.
     *
     * @throws AuthorizationException When user lacks dashboard access
     * @response array<array{name: string}>
     */
    #[Get('/queues', 'api.queue-metrics.queues')]
    public function queues(): JsonResponse
    {
        $this->gateCheckViewDashboard();

        /** @var Collection $queues */
        $queues = QueueMonitor::select('queue')
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(function (QueueMonitor $queueMonitor) {
                return ['name' => $queueMonitor->queue];
            });

        // List of available queue names for filtering.
        return response()->json($queues);
    }

    /**
     * Get comprehensive queue metrics and statistics
     *
     * Returns detailed metrics about queue performance including job counts,
     * execution times, failure rates, and trend analysis over the specified
     * time period for system monitoring and optimization.
     *
     * @param MetricsRequest $request Request with optional aggregateDays parameter
     *
     * @throws AuthorizationException When user lacks dashboard access
     * @response array<array{
     *   title: string,
     *   value: float,
     *   previousValue: int|null,
     *   format: string,
     *   formattedValue: string,
     *   formattedPreviousValue: string|null
     * }>
     */
    #[Get('/metrics', 'api.queue-metrics.metrics')]
    public function metrics(MetricsRequest $request): JsonResponse
    {
        $this->gateCheckViewDashboard();

        /** @var int $aggregateDays Number of days to aggregate metrics over */
        $aggregateDays = $request->query('aggregateDays', 14);

        /** @var array $metrics Comprehensive queue performance metrics */
        $metrics = $this->metricsService->collect(aggregateDays: (int)$aggregateDays);

        // Queue performance metrics and statistics.
        return response()->json($metrics);
    }

    /**
     * Retry a failed queue job
     *
     * Attempts to retry a previously failed job by re-dispatching it to the queue.
     * Only failed jobs that haven't been retried and have valid job UUIDs can be retried.
     * Includes safety checks and error handling.
     *
     * @param RetryJobRequest $request Request for job retry operation
     * @param string $id The queue monitor ID of the job to retry
     *
     * @throws AuthorizationException When user lacks dashboard access
     * @throws ModelNotFoundException When job monitor entry is not found
     * @throws ValidationException When job cannot be retried
     * @response array{
     *   status: string,
     *   message: string
     * }
     */
    #[Post('/retry/{id}', 'api.queue-metrics.retry-job')]
    public function retry(RetryJobRequest $request, string $id): JsonResponse
    {
        $this->gateCheckViewDashboard();

        /** @var QueueMonitor $monitor */
        $monitor = QueueMonitor::whereId($id)
            ->whereStatus(MonitorStatus::Failed)
            ->whereRetried(false)
            ->whereNotNull('job_uuid')
            ->firstOrFail();

        // Verify job can be safely retried
        abort_if(!$monitor->canBeRetried(), 400, 'Job cannot be retried');

        try {
            // Attempt to retry the failed job
            $monitor->retry();

            // Log successful retry for audit trail
            logger()->info('Queue job retried successfully', [
                'monitor_id' => $monitor->id,
                'job_name'   => $monitor->name,
                'queue'      => $monitor->queue,
                'retried_by' => $request->user()->id ?? 'system',
            ]);

            // Successful job retry confirmation.
            return response()->json([
                'status'  => 'success',
                'message' => 'Job has been successfully retried',
            ]);
        } catch (Throwable $exception) {
            // Log retry failure for debugging
            logger()->error('Queue job retry failed', [
                'monitor_id'   => $monitor->id,
                'job_name'     => $monitor->name,
                'error'        => $exception->getMessage(),
                'attempted_by' => $request->user()->id ?? 'system',
            ]);

            // Job retry failure response.
            return response()->json([
                'status'  => 'failed',
                'message' => 'An error occurred while executing the job',
            ], 500);
        }
    }

    /**
     * Delete a specific queue monitor entry
     *
     * Permanently removes a queue monitor record from the system.
     * This only affects monitoring data and does not impact actual queue jobs.
     * Used for cleaning up monitoring history.
     *
     * @param string $id The queue monitor ID to delete
     *
     * @throws AuthorizationException When user lacks dashboard access
     * @throws ModelNotFoundException When monitor entry is not found
     * @status 204
     */
    #[Delete('{id}', 'api.queue-metrics.delete')]
    public function delete(string $id): Response
    {
        $this->gateCheckViewDashboard();

        $deleted = QueueMonitor::whereId($id)->delete();

        if ($deleted) {
            // Log deletion for audit trail
            logger()->info('Queue monitor entry deleted', [
                'monitor_id' => $id,
                'deleted_by' => request()->user()->id ?? 'system',
            ]);
        }

        // Queue monitor entry successfully deleted - no content returned.
        return response(null, 204);
    }

    /**
     * Purge all queue monitor records
     *
     * Completely clears all queue monitoring data from the system.
     * This is a destructive operation that removes all historical job tracking
     * information. Use with extreme caution in production environments.
     *
     * @throws AuthorizationException When user lacks dashboard access
     * @status 204
     */
    #[Delete('/purge', 'api.queue-metrics.purge')]
    public function purge(): Response
    {
        $this->gateCheckViewDashboard();

        // Get count before purging for logging
        $recordCount = QueueMonitor::count();

        // Perform the purge operation
        QueueMonitor::truncate();

        // Log purge operation for audit trail
        logger()->warning('All queue monitor records purged', [
            'records_deleted' => $recordCount,
            'purged_by'       => request()->user()->id ?? 'system',
            'timestamp'       => now(),
        ]);

        // All queue monitor records successfully purged - no content returned.
        return response(null, 204);
    }
}
