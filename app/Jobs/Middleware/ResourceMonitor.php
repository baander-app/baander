<?php

namespace App\Jobs\Middleware;

use App\Jobs\BaseJob;
use App\Modules\Apm\Apm;
use App\Services\SystemMonitor;
use Laravel\Octane\Facades\Octane;
use Swoole\Timer;

class ResourceMonitor
{
    private int $tickId;

    public function handle(BaseJob $job, $next)
    {
        $id = $job->job?->getJobId();
        if (!$id) {
            return $next($job);
        }

        $systemMonitor = new SystemMonitor();

        $this->tickId = Timer::tick(5000, function () use ($systemMonitor, $id) {
            Apm::getTransactionStats();
            $systemLoad = $systemMonitor->getSystemLoad();
            $existing = Octane::table('job_resource_monitor')->get($id);

            $cpuUsage = $systemLoad['cpu']['overall_percentage'];
            $memoryUsage = $systemLoad['memory']['used_percentage'];
            $memoryMb = $systemLoad['memory']['used_mb'];

            if (!$existing) {
                Octane::table('job_resource_monitor')->set($id, [
                    'samples' => 1,
                    'cpu_avg' => $cpuUsage,
                    'memory_avg' => $memoryUsage,
                    'cpu_peak' => $cpuUsage,
                    'memory_peak' => $memoryUsage,
                    'cpu_min' => $cpuUsage,
                    'memory_min' => $memoryUsage,
                    'started_at' => time(),
                    'last_updated' => time(),
                    'last_cpu' => $cpuUsage,
                    'last_memory' => $memoryUsage,
                    'memory_mb' => $memoryMb,
                    'status' => 'running',
                ]);
            } else {
                $samples = $existing['samples'] + 1;

                Octane::table('job_resource_monitor')->set($id, [
                    'samples' => $samples,
                    'cpu_avg' => (($existing['cpu_avg'] * $existing['samples']) + $cpuUsage) / $samples,
                    'memory_avg' => (($existing['memory_avg'] * $existing['samples']) + $memoryUsage) / $samples,
                    'cpu_peak' => max($existing['cpu_peak'], $cpuUsage),
                    'memory_peak' => max($existing['memory_peak'], $memoryUsage),
                    'cpu_min' => min($existing['cpu_min'], $cpuUsage),
                    'memory_min' => min($existing['memory_min'], $memoryUsage),
                    'started_at' => $existing['started_at'],
                    'last_updated' => time(),
                    'last_cpu' => $cpuUsage,
                    'last_memory' => $memoryUsage,
                    'memory_mb' => $memoryMb,
                    'status' => 'running',
                ]);
            }
        });

        try {
            $result = $next($job);

            // Stop the tick and update final status when job completes successfully
            $this->stopMonitoring($id, 'completed');

            return $result;
        } catch (\Throwable $exception) {
            // Stop the tick and update status when job fails
            $this->stopMonitoring($id, 'failed');

            throw $exception;
        }
    }

    private function stopMonitoring(string $id, string $status): void
    {
        // Stop the tick
        Timer::clear($this->tickId);

        // Update final status in the table
        $existing = Octane::table('job_resource_monitor')->get($id);
        if ($existing) {
            Octane::table('job_resource_monitor')->set($id, array_merge($existing, [
                'status' => $status,
                'finished_at' => time(),
                'last_updated' => time(),
            ]));
        }
    }
}