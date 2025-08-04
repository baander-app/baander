<?php

namespace App\Console\Commands;

use App\Models\QueueMonitor;
use App\Modules\Queue\QueueMonitor\MonitorStatus;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use JetBrains\PhpStorm\NoReturn;

class QueueTopCommand extends Command
{
    protected $signature = 'queue:top 
                           {--refresh=2 : Refresh interval in seconds}
                           {--connection= : Redis connection to monitor}
                           {--sort=activity : Sort by: activity, name, pending, failed}
                           {--limit=10 : Number of jobs to display}';

    protected $description = 'Real-time queue monitoring dashboard';

    private int $refreshInterval;
    private string $connection;
    private string $sortBy;
    private int $limit;
    private int $terminalWidth = 120;
    private int $terminalHeight = 30;
    private bool $terminalSetup = false;
    private int $iteration = 0;

    public function handle(): int
    {
        $this->setupEnvironment();
        $this->setupTerminal();

        try {
            while (true) {
                $this->refresh();
                sleep($this->refreshInterval);
            }
        } catch (Exception $e) {
            $this->cleanup();
            $this->error("Fatal: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function setupEnvironment(): void
    {
        $this->refreshInterval = max(1, (int)$this->option('refresh'));
        $this->connection = $this->option('connection') ?: $this->detectConnection();
        $this->sortBy = $this->option('sort');
        $this->limit = max(5, (int)$this->option('limit'));

        $this->getTerminalSize();
        $this->testConnections();
    }

    private function setupTerminal(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_async_signals(true);
        }

        echo "\033[?1049h\033[?25l"; // Alt screen, hide cursor
        $this->terminalSetup = true;
        register_shutdown_function([$this, 'cleanup']);
    }

    #[NoReturn] public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $signalName = match($signal) {
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            SIGHUP => 'SIGHUP',
            SIGQUIT => 'SIGQUIT',
            default => "Signal {$signal}"
        };

        $this->cleanup();
        $this->newLine();
        $this->info("Received {$signalName}, shutting down gracefully...");

        if ($previousExitCode !== 0 && $previousExitCode !== false) {
            $this->warn("Previous exit code: {$previousExitCode}");
        }

        exit(0);
    }


    public function cleanup(): void
    {
        if ($this->terminalSetup) {
            echo "\033[?1049l\033[?25h\033[0m"; // Restore screen, show cursor
            $this->terminalSetup = false;
        }
    }

    private function refresh(): void
    {
        $this->iteration++;
        echo "\033[2J\033[H"; // Clear screen, home cursor

        $this->displayHeader();
        $this->displaySystemStats();
        $this->displayQueues();
        $this->displayJobs();
        $this->displayFooter();
    }

    private function displayHeader(): void
    {
        $now = Carbon::now()->format('H:i:s');
        $load = $this->getSystemLoad();

        echo sprintf(
            "\033[7m %-20s %s %20s \033[0m\n",
            "Queue Monitor",
            str_pad("Load: {$load}  {$now}  Refresh: {$this->refreshInterval}s",
                $this->terminalWidth - 42, ' ', STR_PAD_BOTH),
            "Ctrl+C to exit"
        );
    }

    private function displaySystemStats(): void
    {
        $stats = $this->getOverallStats();

        echo "\n";
        echo sprintf("Tasks: \033[1;32m%s\033[0m running, \033[1;33m%s\033[0m pending, \033[1;31m%s\033[0m failed, \033[0m%s\033[0m completed\n",
            $stats['running'], $stats['pending'], $stats['failed'], $stats['total_today']);

        $throughput = $this->getThroughput();
        echo sprintf("Rate:  %.1f/min (1m), %.1f/min (5m), %.1f/min (15m)\n",
            $throughput['1min'], $throughput['5min'], $throughput['15min']);

        echo sprintf("Mem:   %s used, Workers: %d active\n",
            $this->formatBytes(memory_get_usage(true)), $stats['workers']);
    }

    private function displayQueues(): void
    {
        $queues = $this->getQueueStats();

        echo "\n\033[1mQUEUES\033[0m\n";
        echo sprintf("%-15s %8s %8s %8s %6s\n", 'NAME', 'PENDING', 'ACTIVE', 'FAILED', 'RATE');
        echo str_repeat('─', 50) . "\n";

        foreach (array_slice($queues, 0, 8) as $queue) {
            $rate = $this->getQueueRate($queue['name']);
            echo sprintf("%-15s %8s %8s %8s %6.1f\n",
                $this->truncate($queue['name'], 15),
                $this->colorize($queue['pending'], 'yellow'),
                $this->colorize($queue['active'], 'green'),
                $this->colorize($queue['failed'], 'red'),
                $rate
            );
        }

        if (count($queues) < 8) {
            for ($i = count($queues); $i < 8; $i++) {
                echo str_repeat(' ', 50) . "\n";
            }
        }
    }

    private function displayJobs(): void
    {
        $jobs = $this->getJobStats();

        echo "\n\033[1mJOBS (sorted by {$this->sortBy})\033[0m\n";
        echo sprintf("%-30s %6s %6s %6s %8s %10s %6s\n",
            'CLASS', 'TOTAL', 'RUN', 'FAIL', 'AVG_TIME', 'LAST_RUN', 'RATE');
        echo str_repeat('─', min($this->terminalWidth, 80)) . "\n";

        foreach (array_slice($jobs, 0, $this->limit) as $job) {
            $lastRun = $job['last_run'] ? Carbon::parse($job['last_run'])->diffForHumans(null, true, true) : 'never';

            echo sprintf("%-30s %6s %6s %6s %8s %10s %6.1f\n",
                $this->truncate($job['name'], 30),
                $job['total'],
                $this->colorize($job['running'], 'green'),
                $this->colorize($job['failed'], 'red'),
                $job['avg_time'] ? $job['avg_time'] . 'ms' : 'n/a',
                $this->truncate($lastRun, 10),
                $job['rate_per_min']
            );
        }
    }

    private function displayFooter(): void
    {
        $footerY = $this->terminalHeight - 2;
        echo "\033[{$footerY};1H";
        echo "\033[7m";
        echo sprintf(" Sort: (a)ctivity (n)ame (p)ending (f)ailed | Refresh: %ds | Connection: %s ",
            $this->refreshInterval, $this->connection);
        echo str_repeat(' ', max(0, $this->terminalWidth - strlen(" Sort: (a)ctivity (n)ame (p)ending (f)ailed | Refresh: {$this->refreshInterval}s | Connection: {$this->connection} ")));
        echo "\033[0m";
    }

    private function getOverallStats(): array
    {
        $redis = Redis::connection($this->connection);
        $stats = [
            'pending' => 0,
            'running' => 0,
            'failed' => 0,
            'workers' => $this->getWorkerCount(),
            'total_today' => $this->getTotalToday(),
        ];

        try {
            $queueKeys = $redis->keys('queues:*');
            foreach ($queueKeys as $key) {
                if (preg_match('/^queues:([^:]+)$/', $key, $matches)) {
                    $queue = $matches[1];
                    if (!in_array($queue, ['failed', 'delayed'])) {
                        $stats['pending'] += $redis->llen($key);
                        $stats['running'] += $redis->llen("{$key}:reserved");
                    }
                }
            }
            $stats['failed'] = $redis->llen('queues:failed');
        } catch (Exception $e) {
            // Continue with zeros
        }

        return $stats;
    }

    private function getQueueStats(): array
    {
        $redis = Redis::connection($this->connection);
        $queues = [];

        try {
            $queueKeys = $redis->keys('queues:*');
            $knownQueues = ['default', 'high', 'low'];

            foreach ($queueKeys as $key) {
                if (preg_match('/^queues:([^:]+)$/', $key, $matches)) {
                    $name = $matches[1];
                    if (in_array($name, ['failed', 'delayed'])) continue;

                    $pending = $redis->llen($key);
                    $active = $redis->llen("{$key}:reserved");
                    $failed = 0; // Per-queue failed jobs would need different tracking

                    $queues[] = [
                        'name' => $name,
                        'pending' => $pending,
                        'active' => $active,
                        'failed' => $failed,
                        'total' => $pending + $active,
                    ];
                }
            }

            // Ensure known queues are shown
            foreach ($knownQueues as $queue) {
                if (!collect($queues)->pluck('name')->contains($queue)) {
                    $queues[] = [
                        'name' => $queue,
                        'pending' => 0,
                        'active' => 0,
                        'failed' => 0,
                        'total' => 0,
                    ];
                }
            }

            usort($queues, fn($a, $b) => $b['total'] <=> $a['total']);
        } catch (Exception $e) {
            $queues = [['name' => 'default', 'pending' => 0, 'active' => 0, 'failed' => 0, 'total' => 0]];
        }

        return $queues;
    }

    private function getJobStats(): array
    {
        try {
            $query = (new \App\Models\QueueMonitor)->where('created_at', '>=', now()->subHours(24))
                ->selectRaw('name')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as running', [MonitorStatus::Running->value])
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', [MonitorStatus::Failed->value])
                ->selectRaw('AVG(CASE WHEN finished_at IS NOT NULL AND started_at IS NOT NULL 
                           THEN EXTRACT(EPOCH FROM (finished_at - started_at)) * 1000 END) as avg_time')
                ->selectRaw('MAX(started_at) as last_run')
                ->selectRaw('COUNT(CASE WHEN created_at >= ? THEN 1 END) / 60.0 as rate_per_min', [now()->subHour()])
                ->groupBy('name');

            switch ($this->sortBy) {
                case 'name':
                    $query->orderBy('name');
                    break;
                case 'pending':
                    $query->orderByDesc('running');
                    break;
                case 'failed':
                    $query->orderByDesc('failed');
                    break;
                default: // activity
                    $query->orderByDesc('total');
            }

            return $query->get()->map(function ($item) {
                return [
                    'name' => $this->cleanJobName($item->name),
                    'total' => $item->total,
                    'running' => $item->running,
                    'failed' => $item->failed,
                    'avg_time' => $item->avg_time ? round($item->avg_time) : null,
                    'last_run' => $item->last_run,
                    'rate_per_min' => round($item->rate_per_min, 1),
                ];
            })->toArray();
        } catch (Exception $e) {
            return [];
        }
    }

    private function getThroughput(): array
    {
        try {
            $now = now();
            return [
                '1min' => (new \App\Models\QueueMonitor)->where('finished_at', '>=', $now->copy()->subMinute())
                    ->where('status', MonitorStatus::Succeeded)
                    ->count(),
                '5min' => (new \App\Models\QueueMonitor)->where('finished_at', '>=', $now->copy()->subMinutes(5))
                        ->where('status', MonitorStatus::Succeeded)
                        ->count() / 5,
                '15min' => (new \App\Models\QueueMonitor)->where('finished_at', '>=', $now->copy()->subMinutes(15))
                        ->where('status', MonitorStatus::Succeeded)
                        ->count() / 15,
            ];
        } catch (Exception $e) {
            return ['1min' => 0, '5min' => 0, '15min' => 0];
        }
    }

    private function getQueueRate(string $queueName): float
    {
        // This would need queue-specific tracking
        return 0.0;
    }

    private function getTotalToday(): int
    {
        try {
            return (new \App\Models\QueueMonitor)->whereDate('created_at', today())
                ->where('status', MonitorStatus::Succeeded)
                ->count();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getWorkerCount(): int
    {
        try {
            $processes = shell_exec("ps aux | grep -E 'queue:work|horizon' | grep -v grep | wc -l");
            return max(1, (int)trim($processes ?: '1'));
        } catch (Exception $e) {
            return 1;
        }
    }

    private function getSystemLoad(): string
    {
        try {
            if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/loadavg')) {
                $load = explode(' ', file_get_contents('/proc/loadavg'));
                return sprintf("%.2f %.2f %.2f", $load[0], $load[1], $load[2]);
            }
        } catch (Exception $e) {
            // Ignore
        }
        return 'n/a';
    }

    private function detectConnection(): string
    {
        $queueConn = Config::get('queue.default');
        $queueConfig = Config::get("queue.connections.{$queueConn}");

        if (isset($queueConfig['connection'])) {
            return $queueConfig['connection'];
        }

        return 'default';
    }

    private function testConnections(): void
    {
        try {
            Redis::connection($this->connection)->ping();
            DB::connection()->getPdo();
        } catch (Exception $e) {
            throw new Exception("Connection failed: " . $e->getMessage());
        }
    }

    private function getTerminalSize(): void
    {
        if (function_exists('exec')) {
            $cols = trim(shell_exec('tput cols 2>/dev/null') ?: '120');
            $lines = trim(shell_exec('tput lines 2>/dev/null') ?: '30');
            $this->terminalWidth = (int)$cols;
            $this->terminalHeight = (int)$lines;
        }
    }

    private function colorize(int $value, string $color): string
    {
        if ($value === 0) return '0';

        $code = match($color) {
            'red' => '31',
            'green' => '32',
            'yellow' => '33',
            default => '0'
        };

        return "\033[1;{$code}m{$value}\033[0m";
    }

    private function truncate(string $str, int $length): string
    {
        return strlen($str) > $length ? substr($str, 0, $length - 1) . '…' : $str;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'K', 'M', 'G'];
        $power = min(floor(log($bytes ?: 1, 1024)), count($units) - 1);
        return round($bytes / (1024 ** $power), 1) . $units[$power];
    }

    private function cleanJobName(string $name): string
    {
        return class_basename($name);
    }
}