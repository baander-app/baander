<?php

declare(strict_types=1);

namespace App\QoL\Infrastructure\Swoole;

use Psr\Log\LoggerInterface;
use Swoole\Table;
use Swoole\Timer;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;
use Throwable;

/**
 * Samples CPU and GPU utilization at 1-second intervals.
 *
 * Implements Bootable: creates a Swoole\Table in boot() before fork,
 * then defers Timer::tick() to worker 0 via startSampling().
 * Uses /proc/stat for CPU and nvidia-smi or /sys/class/drm for GPU.
 *
 * CRITICAL: Timer callback does NOT use pooled services (no monolog,
 * no EntityManager, no Redis). Uses error_log() only.
 */
final class CpuGpuSampler implements Bootable
{
    private const int SAMPLE_INTERVAL_MS = 1000;
    private const int TABLE_SIZE = 2; // latest + fallback
    private const string KEY_LATEST = '__latest';

    private bool $booted = false;
    private ?Table $table = null;
    private int $previousCpuTotal = 0;
    private int $previousCpuIdle = 0;

    public function __construct(
        private readonly LoggerInterface $logger,
    )
    {
    }

    public function boot(array $runtimeConfiguration = []): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $this->table = new Table(self::TABLE_SIZE);
        $this->table->column('cpu_percent', Table::TYPE_FLOAT);
        $this->table->column('gpu_percent', Table::TYPE_FLOAT);
        $this->table->column('timestamp', Table::TYPE_INT);
        $this->table->create();

        $this->logger->info('CpuGpuSampler booted — Table created before fork');
    }

    /**
     * Start the sampling timer. Must be called from worker 0's onWorkerStarted.
     * Deferring avoids creating an event loop before the Swoole server starts.
     */
    public function startSampling(): void
    {
        if (!$this->booted || $this->table === null) {
            return;
        }

        $this->logger->info('CpuGpuSampler starting sampling on worker 0');

        // Seed initial CPU reading
        $this->readCpuUsage();

        Timer::tick(self::SAMPLE_INTERVAL_MS, function (): void {
            $this->sample();
        });
    }

    /**
     * Read CPU utilization from /proc/stat.
     *
     * Calculates delta between current and previous readings.
     * /proc/stat format: cpu  user nice system idle iowait irq softirq steal guest guest_nice
     */
    private function readCpuUsage(): float
    {
        $stat = @file_get_contents('/proc/stat');
        if ($stat === false) {
            return $this->getLatest()['cpu_percent'] ?? 0.0;
        }

        $lines = explode("\n", $stat);
        $cpuLine = $lines[0] ?? '';
        $parts = preg_split('/\s+/', $cpuLine);

        if ($parts === false || count($parts) < 5) {
            return $this->getLatest()['cpu_percent'] ?? 0.0;
        }

        // Skip 'cpu' prefix, sum all fields
        $values = array_slice($parts, 1);
        $total = (int)array_sum($values);
        $idle = (int)($values[3] ?? 0);

        $deltaTotal = $total - $this->previousCpuTotal;
        $deltaIdle = $idle - $this->previousCpuIdle;

        $this->previousCpuTotal = $total;
        $this->previousCpuIdle = $idle;

        if ($deltaTotal === 0) {
            return 0.0;
        }

        return round((1.0 - ($deltaIdle / $deltaTotal)) * 100.0, 2);
    }

    /**
     * Get the latest utilization reading.
     *
     * @return array{cpu_percent: float, gpu_percent: float, timestamp: int}|null
     */
    public function getLatest(): ?array
    {
        if ($this->table === null || !$this->table->exists(self::KEY_LATEST)) {
            return null;
        }

        $row = $this->table->get(self::KEY_LATEST);

        return [
            'cpu_percent' => (float)($row['cpu_percent'] ?? 0.0),
            'gpu_percent' => (float)($row['gpu_percent'] ?? 0.0),
            'timestamp' => (int)($row['timestamp'] ?? 0),
        ];
    }

    private function sample(): void
    {
        try {
            $cpuPercent = $this->readCpuUsage();
            $gpuPercent = $this->readGpuUsage();

            $this->table?->set(self::KEY_LATEST, [
                'cpu_percent' => $cpuPercent,
                'gpu_percent' => $gpuPercent,
                'timestamp' => time(),
            ]);
        } catch (Throwable $e) {
            // CRITICAL: use error_log, not $this->logger, to avoid pooled service
            error_log(sprintf('[CpuGpuSampler] Sample failed: %s', $e->getMessage()));
        }
    }

    /**
     * Read GPU utilization.
     *
     * Tries nvidia-smi first (NVIDIA), then /sys/class/drm (Intel/AMD).
     * Returns 0.0 if no GPU monitoring is available.
     */
    private function readGpuUsage(): float
    {
        // Try nvidia-smi (NVIDIA GPUs)
        $nvidia = $this->readNvidiaGpu();
        if ($nvidia !== null) {
            return $nvidia;
        }

        // Try /sys/class/drm (Intel/AMD — limited, returns activity indicator)
        $drm = $this->readDrmGpu();
        if ($drm !== null) {
            return $drm;
        }

        return 0.0;
    }

    private function readNvidiaGpu(): ?float
    {
        $output = @shell_exec('nvidia-smi --query-gpu=utilization.gpu --format=csv,noheader,nounits 2>/dev/null');
        if ($output === null || $output === '') {
            return null;
        }

        $lines = explode("\n", trim($output));
        $first = trim($lines[0] ?? '');

        if (is_numeric($first)) {
            return (float)$first;
        }

        return null;
    }

    private function readDrmGpu(): ?float
    {
        // Read GPU busy percentage from /sys/class/drm/card0/device/gpu_busy_percent
        $path = '/sys/class/drm/card0/device/gpu_busy_percent';
        if (!file_exists($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $value = trim($content);
        if (is_numeric($value)) {
            return (float)$value;
        }

        return null;
    }
}
