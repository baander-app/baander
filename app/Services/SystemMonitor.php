<?php

namespace App\Services;

class SystemMonitor
{
    private array $previousCpuStats = [];

    /**
     * Get comprehensive system load information
     */
    public function getSystemLoad(): array
    {
        return [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get CPU usage information
     */
    public function getCpuUsage(): array
    {
        $loadAvg = sys_getloadavg();
        $cpuCount = $this->getCpuCoreCount();

        return [
            'load_average' => [
                '1_min' => round($loadAvg[0], 2),
                '5_min' => round($loadAvg[1], 2),
                '15_min' => round($loadAvg[2], 2),
            ],
            'load_percentage' => [
                '1_min' => round(($loadAvg[0] / $cpuCount) * 100, 2),
                '5_min' => round(($loadAvg[1] / $cpuCount) * 100, 2),
                '15_min' => round(($loadAvg[2] / $cpuCount) * 100, 2),
            ],
            'cores' => $this->getPerCoreUsage(),
            'core_count' => $cpuCount,
            'overall_percentage' => $this->getOverallCpuPercentage(),
        ];
    }

    /**
     * Get memory usage information
     */
    public function getMemoryUsage(): array
    {
        $meminfo = $this->parseMeminfo();

        $totalMb = round($meminfo['MemTotal'] / 1024, 2);
        $availableMb = round($meminfo['MemAvailable'] / 1024, 2);
        $usedMb = round($totalMb - $availableMb, 2);
        $usedPercentage = $totalMb > 0 ? round(($usedMb / $totalMb) * 100, 2) : 0;

        return [
            'total_mb' => $totalMb,
            'used_mb' => $usedMb,
            'available_mb' => $availableMb,
            'used_percentage' => $usedPercentage,
            'available_percentage' => round(100 - $usedPercentage, 2),
            'buffers_mb' => round($meminfo['Buffers'] / 1024, 2),
            'cached_mb' => round($meminfo['Cached'] / 1024, 2),
            'swap' => [
                'total_mb' => round($meminfo['SwapTotal'] / 1024, 2),
                'used_mb' => round(($meminfo['SwapTotal'] - $meminfo['SwapFree']) / 1024, 2),
                'free_mb' => round($meminfo['SwapFree'] / 1024, 2),
                'used_percentage' => $meminfo['SwapTotal'] > 0
                    ? round((($meminfo['SwapTotal'] - $meminfo['SwapFree']) / $meminfo['SwapTotal']) * 100, 2)
                    : 0,
            ],
        ];
    }

    /**
     * Get per-core CPU usage
     */
    public function getPerCoreUsage(): array
    {
        $cores = [];
        $stat = file_get_contents('/proc/stat');

        if (!$stat) {
            return [];
        }

        $lines = explode("\n", $stat);

        foreach ($lines as $line) {
            if (preg_match('/^cpu(\d+)\s+(.+)/', $line, $matches)) {
                $coreId = (int) $matches[1];
                $values = array_map('intval', preg_split('/\s+/', trim($matches[2])));

                // CPU time values: user, nice, system, idle, iowait, irq, softirq, steal
                $idle = $values[3] + ($values[4] ?? 0); // idle + iowait
                $total = array_sum($values);

                $usage = $total > 0 ? round((($total - $idle) / $total) * 100, 2) : 0;

                $cores[] = [
                    'core_id' => $coreId,
                    'usage_percentage' => $usage,
                    'user' => round(($values[0] / $total) * 100, 2),
                    'system' => round(($values[2] / $total) * 100, 2),
                    'idle' => round(($idle / $total) * 100, 2),
                    'iowait' => round(($values[4] ?? 0 / $total) * 100, 2),
                ];
            }
        }

        return $cores;
    }

    /**
     * Get overall CPU percentage (real-time calculation)
     */
    public function getOverallCpuPercentage(): float
    {
        $stat = file_get_contents('/proc/stat');

        if (!$stat) {
            return 0.0;
        }

        // Get the first line (overall CPU stats)
        $lines = explode("\n", $stat);
        $cpuLine = $lines[0];

        if (!preg_match('/^cpu\s+(.+)/', $cpuLine, $matches)) {
            return 0.0;
        }

        $values = array_map('intval', preg_split('/\s+/', trim($matches[1])));

        // Calculate current usage
        $idle = $values[3] + ($values[4] ?? 0); // idle + iowait
        $total = array_sum($values);

        // If we have previous stats, calculate the difference
        if (!empty($this->previousCpuStats)) {
            $totalDiff = $total - $this->previousCpuStats['total'];
            $idleDiff = $idle - $this->previousCpuStats['idle'];

            if ($totalDiff > 0) {
                $usage = round((($totalDiff - $idleDiff) / $totalDiff) * 100, 2);
            } else {
                $usage = 0.0;
            }
        } else {
            $usage = $total > 0 ? round((($total - $idle) / $total) * 100, 2) : 0.0;
        }

        // Store current stats for next calculation
        $this->previousCpuStats = [
            'total' => $total,
            'idle' => $idle,
        ];

        return $usage;
    }

    /**
     * Get CPU core count
     */
    public function getCpuCoreCount(): int
    {
        // Try nproc first (most reliable)
        $output = shell_exec('nproc 2>/dev/null');
        if ($output) {
            return (int) trim($output);
        }

        // Fallback: count from /proc/cpuinfo
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        if ($cpuinfo) {
            return substr_count($cpuinfo, 'processor');
        }

        return 1; // Ultimate fallback
    }

    /**
     * Parse /proc/meminfo into an array
     */
    protected function parseMeminfo(): array
    {
        $meminfo = file_get_contents('/proc/meminfo');
        $memdata = [];

        if (!$meminfo) {
            return $memdata;
        }

        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^(\w+):\s*(\d+)\s*kB/', $line, $matches)) {
                $memdata[$matches[1]] = (int) $matches[2];
            }
        }

        // Set defaults for missing values
        $defaults = [
            'MemTotal' => 0,
            'MemAvailable' => $memdata['MemFree'] ?? 0,
            'MemFree' => 0,
            'Buffers' => 0,
            'Cached' => 0,
            'SwapTotal' => 0,
            'SwapFree' => 0,
        ];

        return array_merge($defaults, $memdata);
    }

    /**
     * Get disk usage for specified path
     */
    public function getDiskUsage(string $path = '/'): array
    {
        $totalBytes = disk_total_space($path);
        $freeBytes = disk_free_space($path);
        $usedBytes = $totalBytes - $freeBytes;

        return [
            'path' => $path,
            'total_mb' => round($totalBytes / (1024 * 1024), 2),
            'used_mb' => round($usedBytes / (1024 * 1024), 2),
            'free_mb' => round($freeBytes / (1024 * 1024), 2),
            'used_percentage' => $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 2) : 0,
            'free_percentage' => $totalBytes > 0 ? round(($freeBytes / $totalBytes) * 100, 2) : 0,
        ];
    }

    /**
     * Get system uptime
     */
    public function getUptime(): array
    {
        $uptime = file_get_contents('/proc/uptime');

        if (!$uptime) {
            return ['seconds' => 0, 'formatted' => 'Unknown'];
        }

        $uptimeSeconds = (float) explode(' ', $uptime)[0];

        $days = floor($uptimeSeconds / 86400);
        $hours = floor(($uptimeSeconds % 86400) / 3600);
        $minutes = floor(($uptimeSeconds % 3600) / 60);

        return [
            'seconds' => round($uptimeSeconds, 2),
            'formatted' => sprintf('%dd %dh %dm', $days, $hours, $minutes),
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
        ];
    }

    /**
     * Get system health summary
     */
    public function getHealthSummary(): array
    {
        $cpu = $this->getCpuUsage();
        $memory = $this->getMemoryUsage();
        $disk = $this->getDiskUsage();

        return [
            'status' => $this->determineHealthStatus($cpu, $memory, $disk),
            'cpu_load_1min' => $cpu['load_percentage']['1_min'],
            'cpu_overall_percentage' => $cpu['overall_percentage'],
            'memory_used_percentage' => $memory['used_percentage'],
            'disk_used_percentage' => $disk['used_percentage'],
            'uptime' => $this->getUptime()['formatted'],
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Determine overall system health status
     */
    protected function determineHealthStatus(array $cpu, array $memory, array $disk): string
    {
        $cpuLoad = $cpu['load_percentage']['1_min'];
        $memoryUsage = $memory['used_percentage'];
        $diskUsage = $disk['used_percentage'];

        if ($cpuLoad > 90 || $memoryUsage > 95 || $diskUsage > 95) {
            return 'critical';
        }

        if ($cpuLoad > 80 || $memoryUsage > 85 || $diskUsage > 85) {
            return 'warning';
        }

        return 'healthy';
    }
}