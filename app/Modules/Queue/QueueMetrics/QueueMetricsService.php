<?php

namespace App\Modules\Queue\QueueMetrics;

use App\Models\QueueMonitor;
use App\Modules\Queue\QueueMetrics\Models\QueueMetric;
use App\Modules\Queue\QueueMonitor\MonitorStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class QueueMetricsService
{
    /**
     * Collects and aggregates queue metrics for the last configured number of days.
     *
     * This function retrieves various queue metrics, including the total jobs executed,
     * total execution time, and average execution time over a specified period of days
     * configured in the application settings. It also retrieves the same metrics for the
     * preceding period for comparison.
     *
     * Metrics are returned as an array of QueueMetric objects.
     *
     * @return QueueMetric[]
     */
    public function collect(int $aggregateDays = 14)
    {
        // Define the raw expressions using `selectRaw`
        $expressionTotalTime = DB::raw('SUM(EXTRACT(EPOCH FROM (finished_at - started_at))) as total_time_elapsed');
        $expressionAverageTime = DB::raw('AVG(EXTRACT(EPOCH FROM (finished_at - started_at))) as average_time_elapsed');
        $aggregationColumns = [
            DB::raw('COUNT(*) as count'),
            $expressionTotalTime,
            $expressionAverageTime,
        ];

        // Aggregated info for the last `$days` days
        $aggregatedInfo = QueueMonitor::query()
            ->select($aggregationColumns)
            ->where('status', '!=', MonitorStatus::Running->value)
            ->where('started_at', '>=', Carbon::now()->subDays($aggregateDays))
            ->first();

        // Aggregated comparison info for the period between `$days * 2` and `$days` days ago
        $aggregatedComparisonInfo = QueueMonitor::query()
            ->select($aggregationColumns)
            ->where('status', '!=', MonitorStatus::Running->value)
            ->where('started_at', '>=', Carbon::now()->subDays($aggregateDays * 2))
            ->where('started_at', '<=', Carbon::now()->subDays($aggregateDays))
            ->first();

        if (!$aggregatedInfo || !$aggregatedComparisonInfo) {
            return [];
        }

        $res[] = new QueueMetric(
            title: __('Total jobs executed'),
            value: $aggregatedInfo->count ?? 0,
            previousValue: $aggregatedComparisonInfo->count,
            format: '%d',
        );
        $res[] = new QueueMetric(
            title: __('Total execution time'),
            value: $aggregatedComparisonInfo->total_time_elapsed ?? 0,
            previousValue: $aggregatedComparisonInfo->total_time_elapsed,
            format: '%ds',
        );
        $res[] = new QueueMetric(
            title: __('Average execution time'),
            value: $aggregatedInfo->average_time_elapsed ?? 0,
            previousValue: $aggregatedComparisonInfo->average_time_elapsed,
            format: '%0.2fs',
        );

        return $res;
    }
}