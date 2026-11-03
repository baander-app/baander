<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

/**
 * Samples cgroup v2 totals, including native descendants, without network I/O. A cgroup
 * without a limit is measured against the host: CPU usage over the host's CPUs, memory
 * against MemTotal and tasks against the kernel's task bound, so observations stay known.
 */
final class CgroupResourceSampler
{
    /** @var array{at: float, usage: int, periods: int, throttled: int, quota: float}|null */
    private ?array $previous = null;

    public function __construct(private readonly string $directory = '/sys/fs/cgroup', private readonly string $procDirectory = '/proc')
    {
        foreach ([$directory, $procDirectory] as $path) {
            if (!str_starts_with($path, '/') || str_contains($path, "\0")) {
                throw new \InvalidArgumentException('Local absolute cgroup and proc directories are required.');
            }
        }
    }

    public function sample(?float $monotonicNow = null): ResourceObservation
    {
        $now = $monotonicNow ?? hrtime(true) / 1e9;
        try {
            $cpu = $this->read($this->directory, 'cpu.stat');
            $stats = [];
            foreach (explode("\n", trim($cpu)) as $line) {
                if (preg_match('/^([a-z_]+) ([0-9]+)$/D', $line, $match)) {
                    $stats[$match[1]] = $this->integer($match[2]);
                }
            }
            $usage = $stats['usage_usec'] ?? throw new \UnexpectedValueException();
            $periods = $stats['nr_periods'] ?? throw new \UnexpectedValueException();
            $throttled = $stats['nr_throttled'] ?? throw new \UnexpectedValueException();
            $quotaParts = explode(' ', trim($this->read($this->directory, 'cpu.max')));
            if (count($quotaParts) !== 2) {
                throw new \UnexpectedValueException();
            }
            $period = $this->integer($quotaParts[1]);
            $quota = $quotaParts[0] === 'max' ? null : $this->integer($quotaParts[0]);
            $cores = $quota === null ? (float) $this->hostCpus() : ($quota > 0 && $period > 0 ? $quota / $period : null);
            $memory = $this->integer(trim($this->read($this->directory, 'memory.current')));
            $memoryMax = $this->limit('memory.max') ?? $this->hostMemory();
            $pids = $this->integer(trim($this->read($this->directory, 'pids.current')));
            $pidsMax = $this->limit('pids.max') ?? $this->hostTasks();
            $utilization = $throttledRatio = null;
            $previous = $this->previous;
            if ($previous !== null && $cores !== null && $cores === $previous['quota']
                && $now > $previous['at'] && $usage >= $previous['usage']
                && $periods >= $previous['periods'] && $throttled >= $previous['throttled']) {
                $utilization = ($usage - $previous['usage']) / (($now - $previous['at']) * 1e6 * $cores);
                $elapsedPeriods = $periods - $previous['periods'];
                $throttledRatio = $elapsedPeriods > 0 ? min(1.0, ($throttled - $previous['throttled']) / $elapsedPeriods) : 0.0;
            }
            $this->previous = $cores !== null
                ? ['at' => $now, 'usage' => $usage, 'periods' => $periods, 'throttled' => $throttled, 'quota' => $cores]
                : null;
            return new ResourceObservation(
                $utilization !== null ? 'known' : 'unknown', $now, $utilization, $throttledRatio,
                $memory / $memoryMax, $pids / $pidsMax,
                $usage, $cores, $memory, $memoryMax, $pids, $pidsMax,
            );
        } catch (\Throwable) {
            $this->previous = null;
            return new ResourceObservation('unavailable', $now, fault: 'cgroup_unavailable');
        }
    }

    private function read(string $directory, string $name): string
    {
        $value = @file_get_contents($directory . '/' . $name, length: 4097);
        if ($value === false || strlen($value) > 4096) {
            throw new \UnexpectedValueException();
        }
        return $value;
    }

    private function limit(string $name): ?int
    {
        $value = trim($this->read($this->directory, $name));
        return $value === 'max' ? null : (($limit = $this->integer($value)) > 0 ? $limit : null);
    }

    /**
     * CPUs the kernel lists in /proc/stat, which a container without lxcfs sees for the whole
     * host. The per-CPU lines come first, so a bounded prefix holds them on any realistic host.
     */
    private function hostCpus(): int
    {
        $stat = @file_get_contents($this->procDirectory . '/stat', length: 262144);
        $cpus = $stat === false ? 0 : preg_match_all('/^cpu[0-9]+ /m', $stat);
        return $cpus > 0 ? $cpus : throw new \UnexpectedValueException();
    }

    private function hostMemory(): int
    {
        if (preg_match('/^MemTotal:\s+([0-9]+) kB$/m', $this->read($this->procDirectory, 'meminfo'), $match) !== 1
            || ($kibibytes = $this->integer($match[1])) < 1) {
            throw new \UnexpectedValueException();
        }
        return $kibibytes * 1024;
    }

    /** Every task takes a PID and counts against the thread limit, so the lower bound applies. */
    private function hostTasks(): int
    {
        $bound = min($this->integer(trim($this->read($this->procDirectory, 'sys/kernel/pid_max'))),
            $this->integer(trim($this->read($this->procDirectory, 'sys/kernel/threads-max'))));
        return $bound > 0 ? $bound : throw new \UnexpectedValueException();
    }

    private function integer(string $value): int
    {
        if (!ctype_digit($value) || ($number = filter_var($value, FILTER_VALIDATE_INT)) === false || $number < 0) {
            throw new \UnexpectedValueException();
        }
        return $number;
    }
}
