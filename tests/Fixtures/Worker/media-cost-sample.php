<?php

declare(strict_types=1);

// Bounded per-process cost sampler inside one disposable worker unit. The first
// line describes the host and unit limits; every later line is one sample.
// A process's arguments are written once, the first time its identity is seen.

$seconds = (float) ($argv[1] ?? 60);
$unit = $argv[2] ?? 'execution';
$interval = 250_000;
$progressDirectory = dirname(__DIR__, 3) . '/storage/two-command/cost-progress';
$deadline = hrtime(true) + (int) ($seconds * 1e9);

$read = static fn (string $path, int $length = 65_536): ?string => is_string($value = @file_get_contents($path, false, null, 0, $length)) ? $value : null;
$governors = [];
foreach (glob('/sys/devices/system/cpu/cpu[0-9]*/cpufreq/scaling_governor') ?: [] as $path) {
    $governors[basename(dirname($path, 2))] = trim((string) $read($path));
}
$models = [];
preg_match_all('/^model name\s*:\s*(.+)$/m', (string) $read('/proc/cpuinfo', 1_048_576), $models);
$header = [
    'unit' => $unit,
    'monotonic' => hrtime(true) / 1e9,
    'clockTicks' => 100,
    'cpuModel' => $models[1][0] ?? null,
    'hostLogicalCpus' => count($models[1]),
    'scalingGovernors' => array_count_values($governors),
    'scalingMaxKhz' => is_string($max = $read('/sys/devices/system/cpu/cpu0/cpufreq/scaling_max_freq')) ? (int) $max : null,
    'boost' => is_string($boost = $read('/sys/devices/system/cpu/cpufreq/boost')) ? trim($boost) : null,
    'loadavg' => trim((string) $read('/proc/loadavg')),
    'memTotalKib' => preg_match('/^MemTotal:\s+(\d+)/m', (string) $read('/proc/meminfo'), $memory) === 1 ? (int) $memory[1] : null,
];
foreach (['cpuset.cpus.effective', 'cpu.max', 'memory.max', 'pids.max'] as $file) {
    $header['cgroup'][$file] = is_string($value = $read('/sys/fs/cgroup/' . $file)) ? trim($value) : null;
}
echo json_encode(['header' => $header], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";

/** @var array<string,true> $described */
$described = [];
/** @var array<string,true> $native */
$native = [];
while (hrtime(true) < $deadline) {
    $uptime = (float) explode(' ', (string) $read('/proc/uptime'))[0];
    $sample = ['t' => hrtime(true) / 1e9, 'uptime' => $uptime, 'loadavg' => trim((string) $read('/proc/loadavg'))];
    $cpu = (string) $read('/sys/fs/cgroup/cpu.stat');
    $sample['cgroup'] = [
        'usageUsec' => preg_match('/^usage_usec (\d+)/m', $cpu, $usage) === 1 ? (int) $usage[1] : null,
        'throttledUsec' => preg_match('/^throttled_usec (\d+)/m', $cpu, $throttled) === 1 ? (int) $throttled[1] : null,
        'memoryCurrent' => is_string($current = $read('/sys/fs/cgroup/memory.current')) ? (int) $current : null,
        'memoryPeak' => is_string($peak = $read('/sys/fs/cgroup/memory.peak')) ? (int) $peak : null,
        'pidsCurrent' => is_string($pids = $read('/sys/fs/cgroup/pids.current')) ? (int) $pids : null,
    ];
    $paths = glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [];
    if (count($paths) > 4096) {
        throw new RuntimeException('Cost sampling exceeded its bounded process inventory.');
    }
    $sample['processes'] = [];
    foreach ($paths as $path) {
        $pid = (int) basename($path);
        if ($pid === getmypid()) {
            continue;
        }
        $stat = $read($path . '/stat', 4096);
        $status = $read($path . '/status', 16_384);
        if ($stat === null || $status === null || ($close = strrpos($stat, ')')) === false) {
            continue; // The process exited while /proc was being enumerated.
        }
        $fields = explode(' ', trim(substr($stat, $close + 2)));
        $identity = $pid . ':' . $fields[19];
        if (!isset($described[$identity])) {
            $arguments = $read($path . '/cmdline', 65_536);
            if ($arguments === null || $arguments === '') {
                continue; // Kernel threads and exiting processes carry no arguments.
            }
            $described[$identity] = true;
            if (count($described) > 8192) {
                throw new RuntimeException('Cost sampling exceeded its bounded identity inventory.');
            }
            $sample['started'][$identity] = explode("\0", rtrim($arguments, "\0"));
            if (basename($sample['started'][$identity][0]) === 'ffmpeg' || in_array($sample['started'][$identity][1] ?? '', ['/usr/bin/ffmpeg', __DIR__ . '/media-control-ffmpeg.php'], true)) {
                $native[$identity] = true;
            }
        }
        $kib = static fn (string $key): ?int => preg_match('/^' . $key . ':\s+(\d+) kB$/m', $status, $match) === 1 ? (int) $match[1] : null;
        $process = [
            'id' => $identity,
            'ppid' => (int) $fields[1],
            'cpuTicks' => (int) $fields[11] + (int) $fields[12],
            'startTicks' => (int) $fields[19],
            'threads' => (int) $fields[17],
            'rssKib' => $kib('VmRSS'),
            'hwmKib' => $kib('VmHWM'),
        ];
        if (isset($native[$identity])) {
            // Per-thread CPU shows how an encoder spreads work beyond its configured thread counts.
            foreach (glob($path . '/task/[0-9]*/stat') ?: [] as $task) {
                $taskStat = $read($task, 4096);
                if ($taskStat !== null && ($taskClose = strrpos($taskStat, ')')) !== false) {
                    $taskFields = explode(' ', trim(substr($taskStat, $taskClose + 2)));
                    $process['threadTicks'][basename(dirname($task))] = (int) $taskFields[11] + (int) $taskFields[12];
                }
            }
        }
        $progress = isset($native[$identity]) ? $read($progressDirectory . '/' . $pid . '.txt', 1_048_576) : null;
        if ($progress !== null && ($end = strrpos($progress, "\nprogress=")) !== false) {
            $block = substr($progress, 0, $end);
            $block = substr($block, (int) strrpos($block, "\nprogress=") ?: 0);
            $values = [];
            foreach (['frame', 'out_time_us'] as $key) {
                if (preg_match_all('/^' . $key . '=(\d+)$/m', $block, $found) > 0) {
                    $values[$key] = (int) end($found[1]);
                }
            }
            $process['progress'] = $values;
        }
        $sample['processes'][] = $process;
    }
    echo json_encode($sample, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    flush();
    if (($stopFile = getenv('BAANDER_TEST_SAMPLER_STOP_FILE')) !== false && is_file($stopFile)) {
        break;
    }
    usleep($interval);
}
