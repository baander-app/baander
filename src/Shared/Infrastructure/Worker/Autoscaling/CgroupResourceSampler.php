<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

/** Samples cgroup v2 totals, including native descendants, without network I/O. */
final class CgroupResourceSampler
{
    /** @var array{at: float, usage: int, periods: int, throttled: int, quota: float}|null */
    private ?array $previous = null;

    public function __construct(private readonly string $directory = '/sys/fs/cgroup')
    {
        if (!str_starts_with($directory, '/') || str_contains($directory, "\0")) {
            throw new \InvalidArgumentException('A local absolute cgroup directory is required.');
        }
    }

    public function sample(?float $monotonicNow = null): ResourceObservation
    {
        $now = $monotonicNow ?? hrtime(true) / 1e9;
        try {
            $cpu = $this->read('cpu.stat');
            $stats = [];
            foreach (explode("\n", trim($cpu)) as $line) {
                if (preg_match('/^([a-z_]+) ([0-9]+)$/D', $line, $match)) {
                    $stats[$match[1]] = $this->integer($match[2]);
                }
            }
            $usage = $stats['usage_usec'] ?? throw new \UnexpectedValueException();
            $periods = $stats['nr_periods'] ?? throw new \UnexpectedValueException();
            $throttled = $stats['nr_throttled'] ?? throw new \UnexpectedValueException();
            $quotaParts = explode(' ', trim($this->read('cpu.max')));
            if (count($quotaParts) !== 2) {
                throw new \UnexpectedValueException();
            }
            $period = $this->integer($quotaParts[1]);
            $quota = $quotaParts[0] === 'max' ? null : $this->integer($quotaParts[0]);
            $cores = $quota !== null && $quota > 0 && $period > 0 ? $quota / $period : null;
            $memory = $this->integer(trim($this->read('memory.current')));
            $memoryMax = $this->limit('memory.max');
            $pids = $this->integer(trim($this->read('pids.current')));
            $pidsMax = $this->limit('pids.max');
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
            $known = $utilization !== null && $memoryMax !== null && $pidsMax !== null;
            return new ResourceObservation(
                $known ? 'known' : 'unknown', $now, $utilization, $throttledRatio,
                $memoryMax !== null ? $memory / $memoryMax : null,
                $pidsMax !== null ? $pids / $pidsMax : null,
                $usage, $cores, $memory, $memoryMax, $pids, $pidsMax,
            );
        } catch (\Throwable) {
            $this->previous = null;
            return new ResourceObservation('unavailable', $now, fault: 'cgroup_unavailable');
        }
    }

    private function read(string $name): string
    {
        $value = @file_get_contents($this->directory . '/' . $name, length: 4097);
        if ($value === false || strlen($value) > 4096) {
            throw new \UnexpectedValueException();
        }
        return $value;
    }

    private function limit(string $name): ?int
    {
        $value = trim($this->read($name));
        return $value === 'max' ? null : (($limit = $this->integer($value)) > 0 ? $limit : null);
    }

    private function integer(string $value): int
    {
        if (!ctype_digit($value) || ($number = filter_var($value, FILTER_VALIDATE_INT)) === false || $number < 0) {
            throw new \UnexpectedValueException();
        }
        return $number;
    }
}
