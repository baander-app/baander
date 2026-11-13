<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Qualification;

/** Host and cgroup counters share one sampling clock; process RSS is never added to cgroup memory. */
final class HostSampler
{
    public const int MAX_LINE_BYTES = 1048576;
    private const int MAX_PROCESSES = 4096;
    /** Kernel task flag set once a process has entered exit and released its memory map. */
    private const int PF_EXITING = 0x4;
    /** @var list<string> */
    private array $missing = [];

    public function __construct(private readonly string $procRoot = '/proc', private readonly string $cgroupRoot = '/sys/fs/cgroup') {}

    /** @param array<string,array{pid:int,cgroup:string}> $roles
     * @return array<string,mixed>
     */
    public function capture(float $monotonic, int $sequence, array $roles): array
    {
        if (!is_finite($monotonic) || $monotonic < 0 || $sequence < 0) {
            throw new \InvalidArgumentException('Sample clock and sequence must be non-negative.');
        }
        $started = hrtime(true);
        if (count($roles) > 512) {
            throw new \InvalidArgumentException('Role mapping exceeds its bounded capacity.');
        }
        $this->missing = [];
        $meminfo = $this->keyValues($this->read($this->procRoot . '/meminfo', 'host:meminfo'), true);
        $vmstat = $this->keyValues($this->read($this->procRoot . '/vmstat', 'host:vmstat'));
        $host = [
            'memTotalBytes' => $this->required($meminfo, 'MemTotal', 'host:meminfo'),
            'memAvailableBytes' => $this->required($meminfo, 'MemAvailable', 'host:meminfo'),
            'swapTotalBytes' => $this->required($meminfo, 'SwapTotal', 'host:meminfo'),
            'swapFreeBytes' => $this->required($meminfo, 'SwapFree', 'host:meminfo'),
            'vmstat' => ['pswpin' => $this->required($vmstat, 'pswpin', 'host:vmstat'), 'pswpout' => $this->required($vmstat, 'pswpout', 'host:vmstat')],
            'memoryPressure' => $this->pressure($this->read($this->procRoot . '/pressure/memory', 'host:pressure/memory')),
        ];
        $paths = [];
        foreach ($roles as $role => $record) {
            self::validateMapping($role, $record);
            $path = '/' . trim($record['cgroup'], '/');
            if ($path === rtrim($this->cgroupRoot, '/')) {
                $path = '/';
            }
            if (str_starts_with($path, rtrim($this->cgroupRoot, '/') . '/')) {
                $path = substr($path, strlen(rtrim($this->cgroupRoot, '/')));
            }
            foreach ($paths as $other) {
                if ($path !== $other && ($path === '/' || $other === '/' || str_starts_with($path, $other . '/') || str_starts_with($other, $path . '/'))) {
                    throw new \InvalidArgumentException('Mapped cgroups overlap; parent and child counters cannot be summed.');
                }
            }
            $paths[$role] = $path;
        }
        $groups = [];
        $processRoles = [];
        $groupPids = [];
        $rootPids = array_column($roles, 'pid');
        foreach ($roles as $role => $record) {
            $path = $paths[$role];
            $directory = rtrim($this->cgroupRoot, '/') . $path;
            $stat = @stat($directory);
            if ($stat === false) {
                $this->missing[] = 'cgroup:' . $path;
                continue;
            }
            $identity = $stat['dev'] . ':' . $stat['ino'];
            $processRoles[$record['pid']][] = $role;
            if (isset($groups[$identity])) {
                $groups[$identity]['roles'][] = $role;
                foreach ($groupPids[$identity] as $pid) {
                    $processRoles[$pid][] = $role;
                }
                continue;
            }
            $group = ['path' => $path, 'roles' => [$role]];
            foreach (['memory.current' => 'memoryCurrentBytes', 'memory.peak' => 'memoryPeakBytes', 'memory.max' => 'memoryMaxBytes',
                'memory.swap.current' => 'swapCurrentBytes', 'memory.swap.peak' => 'swapPeakBytes', 'memory.swap.max' => 'swapMaxBytes',
                'pids.current' => 'pidsCurrent', 'pids.max' => 'pidsMax'] as $file => $key) {
                $raw = $this->read($directory . '/' . $file, 'cgroup:' . $path . '/' . $file);
                $value = $raw === null ? null : trim($raw);
                if ($value !== null && $value !== 'max' && !ctype_digit($value)) {
                    $this->missing[] = 'cgroup:' . $path . '/' . $file . ':malformed';
                    $value = null;
                }
                $group[$key] = $value === null || $value === 'max' ? $value : (int) $value;
            }
            $events = $this->read($directory . '/memory.events', 'cgroup:' . $path . '/memory.events');
            $group['memoryEvents'] = $events === null ? null : $this->keyValues($events);
            foreach (['oom', 'oom_kill'] as $counter) {
                $this->required($group['memoryEvents'] ?? [], $counter, 'cgroup:' . $path . '/memory.events');
            }
            $groupPids[$identity] = [];
            $pids = $this->read($directory . '/cgroup.procs', 'cgroup:' . $path . '/cgroup.procs');
            foreach (preg_split('/\s+/', trim($pids ?? ''), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $pid) {
                if (!ctype_digit($pid) || (int) $pid <= 0) {
                    $this->missing[] = 'cgroup:' . $path . '/cgroup.procs:malformed';
                    continue;
                }
                $processRoles[(int) $pid][] = $role;
                $groupPids[$identity][] = (int) $pid;
                if (count($processRoles) > self::MAX_PROCESSES) {
                    throw new \RuntimeException('Process observation exceeds its bounded capacity.');
                }
            }
            $groups[$identity] = $group;
        }
        $processes = [];
        $nativeCount = 0;
        $nativeThreads = 0;
        $exitedDuringCapture = [];
        foreach ($processRoles as $pid => $owners) {
            $before = count($this->missing);
            $process = $this->process($pid, array_values(array_unique($owners)));
            if (!in_array($pid, $rootPids, true) && (!is_dir(rtrim($this->procRoot, '/') . '/' . $pid) || ($process['exiting'] ?? false))) {
                $this->missing = array_slice($this->missing, 0, $before);
                $exitedDuringCapture[] = $pid;
                continue;
            }
            if (in_array($pid, $rootPids, true) && ($process['exiting'] ?? false)) {
                $this->missing[] = 'process:' . $pid . '/exited';
            }
            if ($process === null) {
                continue;
            }
            $processes[$pid . ':' . $process['startTicks']] = $process;
            if ($process['native']) {
                ++$nativeCount;
                $nativeThreads += $process['threads'] ?? 0;
            }
        }
        return ['version' => 1, 'sequence' => $sequence, 'monotonic' => $monotonic, 'host' => $host,
            'captureWindowSeconds' => (hrtime(true) - $started) / 1e9, 'exitedDuringCapture' => $exitedDuringCapture,
            'cgroups' => $groups, 'processes' => $processes, 'nativeProcessCount' => $nativeCount, 'nativeThreadCount' => $nativeThreads,
            'missing' => array_values(array_unique($this->missing))];
    }

    /** The whole-host memory and swap sizes; a missing counter reads as null.
     * @return array{memTotalBytes:?int,swapTotalBytes:?int}
     */
    public function memory(): array
    {
        $this->missing = [];
        $meminfo = $this->keyValues($this->read($this->procRoot . '/meminfo', 'host:meminfo'), true);
        return ['memTotalBytes' => $meminfo['MemTotal'] ?? null, 'swapTotalBytes' => $meminfo['SwapTotal'] ?? null];
    }

    /** @param array<string,mixed> $sample */
    public function append(string $path, array $sample): void
    {
        $line = json_encode($sample, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        if (strlen($line) > self::MAX_LINE_BYTES) {
            throw new \RuntimeException('Sample exceeds bounded JSONL record size.');
        }
        $stream = @fopen($path, 'ab');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open measurement output.');
        }
        try {
            if (!flock($stream, LOCK_EX) || fwrite($stream, $line) !== strlen($line) || !fflush($stream)) {
                throw new \RuntimeException('Cannot append complete measurement sample.');
            }
        } finally {
            fclose($stream);
        }
    }

    private static function validateMapping(mixed $role, mixed $record): void
    {
        if (!is_string($role) || $role === '' || !is_array($record) || !is_int($record['pid'] ?? null) || $record['pid'] <= 0
            || !is_string($record['cgroup'] ?? null) || str_contains($record['cgroup'], "\0")
            || in_array('..', explode('/', $record['cgroup']), true)) {
            throw new \InvalidArgumentException('Role mapping requires a name, positive host PID and safe cgroup path.');
        }
    }

    private function read(string $path, string $label): ?string
    {
        $raw = @file_get_contents($path, length: 131073);
        if ($raw === false || strlen($raw) > 131072) {
            $this->missing[] = $label;
            return null;
        }
        return $raw;
    }

    /** @return array<string,int> */
    private function keyValues(?string $raw, bool $kilobytes = false): array
    {
        $values = [];
        foreach (explode("\n", $raw ?? '') as $line) {
            if ($line === '') {
                continue;
            }
            $pattern = $kilobytes ? '/^([A-Za-z_][A-Za-z0-9_]*):\s+([0-9]+) kB$/' : '/^([A-Za-z_][A-Za-z0-9_]*)\s+([0-9]+)$/';
            if (preg_match($pattern, trim($line), $matches) === 1) {
                $values[$matches[1]] = (int) $matches[2] * ($kilobytes ? 1024 : 1);
            } elseif (!$kilobytes) {
                $this->missing[] = 'counter:malformed';
            }
        }
        return $values;
    }

    /** @param array<string,int> $values */
    private function required(array $values, string $key, string $label): ?int
    {
        if (!array_key_exists($key, $values)) {
            $this->missing[] = $label . ':' . $key;
            return null;
        }
        return $values[$key];
    }

    /** @return array<string,array<string,int|float>> */
    private function pressure(?string $raw): array
    {
        $result = [];
        foreach (explode("\n", $raw ?? '') as $line) {
            if (preg_match('/^(some|full) avg10=([0-9]+\.[0-9]+) avg60=([0-9]+\.[0-9]+) avg300=([0-9]+\.[0-9]+) total=([0-9]+)$/', trim($line), $match) === 1) {
                $result[$match[1]] = ['avg10' => (float) $match[2], 'avg60' => (float) $match[3], 'avg300' => (float) $match[4], 'total' => (int) $match[5]];
            }
        }
        if (count($result) !== 2) {
            $this->missing[] = 'host:pressure/memory:malformed';
        }
        return $result;
    }

    /** @param list<string> $roles
     * @return array<string,mixed>|null
     */
    private function process(int $pid, array $roles): ?array
    {
        $directory = rtrim($this->procRoot, '/') . '/' . $pid;
        // Read the executable before the state. The kernel drops the executable link when exit begins, so a
        // failed read is classified by the state read afterwards: an exiting, zombie or vanished process is
        // exited during capture; a running process without an executable is missing telemetry.
        $executable = @readlink($directory . '/exe');
        $raw = $this->read($directory . '/stat', 'process:' . $pid . '/stat');
        $boundary = $raw === null ? false : strrpos($raw, ') ');
        $fields = $boundary === false ? [] : preg_split('/\s+/', trim(substr($raw, $boundary + 2)));
        if (!isset($fields[19]) || !ctype_digit($fields[19])) {
            $this->missing[] = 'process:' . $pid . '/stat:malformed';
            return null;
        }
        $status = $this->read($directory . '/status', 'process:' . $pid . '/status');
        $name = preg_match('/^Name:\s+(.+)$/m', $status ?? '', $match) === 1 ? $match[1] : null;
        $threads = preg_match('/^Threads:\s+([0-9]+)$/m', $status ?? '', $match) === 1 ? (int) $match[1] : null;
        $rss = preg_match('/^VmRSS:\s+([0-9]+) kB$/m', $status ?? '', $match) === 1 ? (int) $match[1] * 1024 : null;
        if ($name === null || $threads === null) {
            $this->missing[] = 'process:' . $pid . '/status:malformed';
        }
        if ($executable === false) {
            $this->missing[] = 'process:' . $pid . '/exe';
        }
        $native = in_array(basename($executable ?: (string) $name), ['ffmpeg', 'ffprobe', 'ffmpeg.qualification-real', 'ffprobe.qualification-real'], true);
        $command = $native ? $this->read($directory . '/cmdline', 'process:' . $pid . '/cmdline') : null;
        $exiting = $fields[0] === 'Z' || (ctype_digit($fields[6]) && ((int) $fields[6] & self::PF_EXITING) !== 0);
        return ['pid' => $pid, 'startTicks' => (int) $fields[19], 'state' => $fields[0], 'exiting' => $exiting, 'roles' => $roles, 'name' => $name,
            'executable' => $executable ?: null, 'threads' => $threads, 'rssBytes' => $rss, 'native' => $native,
            'command' => $command === null ? null : str_replace("\0", ' ', rtrim($command, "\0"))];
    }
}
