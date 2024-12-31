<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\QueueMonitor\RetryJobRequest;
use App\Models\TokenAbility;
use App\Packages\QueueMonitor\MonitorStatus;
use App\Extensions\{BaseBuilder, JsonAnonymousResourceCollection, JsonPaginator};
use App\Http\Controllers\Controller;
use App\Http\Requests\QueueMetrics\{MetricsRequest, ShowQueueMetricsRequest};
use App\Http\Resources\QueueMonitor\QueueMonitorResource;
use App\Models\QueueMonitor;
use App\Services\QueueMetrics\QueueMetricsService;
use Spatie\RouteAttributes\Attributes\{Delete, Get, Middleware, Post, Prefix};

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
     * Get a collection of monitor entries
     *
     * @param ShowQueueMetricsRequest $request
     * @return JsonAnonymousResourceCollection<JsonPaginator<QueueMonitorResource>>
     */
    #[Get('/', 'api.queue-metrics.show')]
    public function show(ShowQueueMetricsRequest $request)
    {
        $this->gateCheckViewDashboard();

        $queuedFirst = $request->query('queuedFirst');
        $status = $request->query('status');
        $queue = $request->query('queue');
        $name = $request->query('name');

        $models = QueueMonitor::when($status, function (BaseBuilder $query, $status) {
            return $query->where('status', $status);
        })->when($queue, function (BaseBuilder $query, $queue) {
            return $query->where('queue', $queue);
        })->when($name, function (BaseBuilder $query, $name) {
            return $query->where('name', 'like', '%' . $name . '%');
        })->when($queuedFirst, function (BaseBuilder $query) {
            return $query->orderByRaw('started_at DESC NULLS LAST');
        })->paginate();

        return QueueMonitorResource::collection($models);
    }

    /**
     * Get a list of queue names
     *
     * @response array{
     *   name: string
     * }[]
     */
    #[Get('/queues', 'api.queue-metrics.queues')]
    public function queues()
    {
        $this->gateCheckViewDashboard();

        $queues = QueueMonitor::select('queue')
            ->groupBy('queue')
            ->get()
            ->map(function (QueueMonitor $queueMonitor) {
                return ['name' => $queueMonitor->queue];
            });

        return response()->json($queues);
    }

    /**
     * Get a metrics collection
     *
     * @response array{
     *   title: string,
     *   value: float,
     *   previousValue: int|null,
     *   format: string,
     *   formattedValue: string,
     *   formattedPreviousValue: string|null,
     * }[]
     */
    #[Get('/metrics', 'api.queue-metrics.metrics')]
    public function metrics(MetricsRequest $request)
    {
        $this->gateCheckViewDashboard();

        $aggregateDays = $request->query('aggregateDays', 14);

        $metrics = $this->metricsService->collect(aggregateDays: (int)$aggregateDays);

        return response()->json($metrics);
    }

    /**
     * Retry a job
     *
     * @param RetryJobRequest $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    #[Post('/retry/{id}', 'api.queue-metrics.retry-job')]
    public function retry(RetryJobRequest $request, string $id)
    {
        $this->gateCheckViewDashboard();

        $monitor = QueueMonitor::whereId($id)
            ->whereStatus(MonitorStatus::Failed)
            ->whereRetried(false)
            ->whereNotNull('job_uuid')
            ->firstOrFail();

        abort_if(!$monitor->canBeRetried(), 400, 'Job cannot be retried');

        try {
            $monitor->retry();
        } catch (\Throwable $exception) {
            return response()->json([
                'status'  => 'failed',
                'message' => 'An error occurred while executing the job',
            ]);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Job has been successfully retried',
        ]);
    }

    /**
     * Delete by id
     *
     * @param string $id
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Foundation\Application|\Illuminate\Http\Response
     */
    #[Delete('{id}', 'api.queue-metrics.delete')]
    public function delete(string $id)
    {
        $this->gateCheckViewDashboard();

        QueueMonitor::whereId($id)->delete();

        return response(null, 204);
    }

    /**
     * Purge all records
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Foundation\Application|\Illuminate\Http\Response
     */
    #[Delete('/purge', 'api.queue-metrics.purge')]
    public function purge()
    {
        $this->gateCheckViewDashboard();

        QueueMonitor::truncate();

        return response(null, 204);
    }
}