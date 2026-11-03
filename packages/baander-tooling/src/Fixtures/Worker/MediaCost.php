<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

/**
 * Turns per-process samples from both worker units and the client's phase
 * boundaries into a per-job cost receipt: peak memory, threads, CPU per second of
 * output and encode speed for every native media process and media job child.
 */
final class MediaCost
{
    private const float CLOCK_TICKS = 100.0;
    /** Encoder warm-up excluded from the steady-state window, in seconds of output. */
    private const float WARM_UP_OUTPUT_SECONDS = 3.0;

    /** @return array<string,mixed> */
    public static function receipt(string $artifacts): array
    {
        $execution = self::stream($artifacts . '/cost-execution-samples.jsonl');
        $control = self::stream($artifacts . '/cost-control-samples.jsonl');
        $client = Reports::json($artifacts . '/media-cost-client.json');
        $evidence = $client['evidence'] ?? [];
        Reports::require(is_array($evidence['phases'] ?? null) && $evidence['phases'] !== [], 'Cost client recorded no phases');
        $sources = $evidence['sources'];
        if (is_file($artifacts . '/media-cost-replay.json')) {
            $evidence['phases'][] = Reports::json($artifacts . '/media-cost-replay.json');
        }
        $native = self::processes($execution['samples'], $execution['arguments']);
        $children = self::processes($control['samples'], $control['arguments']);
        $host = is_file($artifacts . '/cost-host-samples.jsonl') ? Reports::samples($artifacts . '/cost-host-samples.jsonl') : [];
        $phases = [];
        foreach ($evidence['phases'] as $phase) {
            $start = Reports::number($phase['startedAt']);
            $end = Reports::number($phase['endedAt']);
            $jobs = [];
            foreach ($native as $process) {
                $kind = self::nativeKind($process['arguments']);
                if ($kind === null || !self::overlaps($process, $start, $end)) {
                    continue;
                }
                $jobs[] = $kind + self::nativeCost($process, $kind, $sources);
            }
            $media = [];
            foreach ($children as $process) {
                $role = self::childRole($process, $children);
                if ($role !== null && self::overlaps($process, $start, $end)) {
                    $media[] = $role + self::memory($process);
                }
            }
            $window = Reports::window($execution['samples'], $start, $end);
            $phases[] = [
                'name' => $phase['name'],
                'seconds' => round($end - $start, 3),
                'loadavg' => ['start' => $window[0]['loadavg'] ?? null, 'end' => $window[array_key_last($window)]['loadavg'] ?? null],
                'native' => $jobs,
                'maximumConcurrent' => self::concurrency($window, $native),
                'executionUnit' => self::unit($window),
                'mediaChildren' => $media,
                'services' => self::services(array_values(array_filter(
                    array_slice($host, 1),
                    static fn(array $sample): bool => $start <= $sample['t'] && $sample['t'] <= $end,
                ))),
                'client' => array_diff_key($phase, array_flip(['name', 'startedAt', 'endedAt'])),
            ];
        }
        return [
            'version' => 1,
            'host' => $execution['header'],
            'controlUnit' => $control['header']['cgroup'] ?? null,
            'serviceCgroups' => $host[0]['header']['cgroups'] ?? null,
            'sources' => $sources,
            'phases' => $phases,
            'summary' => self::summary($phases),
        ];
    }

    /** The largest peak resident memory of one native process of a kind seen so far, in MiB. */
    public static function peakNativeRssMiB(string $path, string $kind): float
    {
        $stream = self::stream($path, EvidenceMode::Live);
        $peak = 0.0;
        foreach (self::processes($stream['samples'], $stream['arguments']) as $process) {
            if ((self::nativeKind($process['arguments'])['kind'] ?? null) === $kind) {
                $peak = max($peak, self::memory($process)['peakRssMiB']);
            }
        }
        return $peak;
    }

    /** @return array{header:array<string,mixed>,samples:list<array<string,mixed>>,arguments:array<string,list<string>>} */
    private static function stream(string $path, EvidenceMode $mode = EvidenceMode::Completed): array
    {
        $records = Reports::samples($path, $mode);
        Reports::require(isset($records[0]['header']) && count($records) >= 2, 'Cost sampler omitted its header or samples: ' . basename($path));
        $arguments = [];
        $samples = [];
        foreach (array_slice($records, 1) as $sample) {
            foreach ($sample['started'] ?? [] as $identity => $argv) {
                $arguments[(string) $identity] = $argv;
            }
            unset($sample['started']);
            $sample['monotonic'] = $sample['t'];
            $samples[] = $sample;
        }
        return ['header' => $records[0]['header'], 'samples' => $samples, 'arguments' => $arguments];
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @param array<string,list<string>> $arguments
     * @return array<string,array{id:string,arguments:list<string>,samples:list<array<string,mixed>>}>
     */
    private static function processes(array $samples, array $arguments): array
    {
        $processes = [];
        foreach ($samples as $sample) {
            foreach ($sample['processes'] as $process) {
                $id = $process['id'];
                if (!isset($arguments[$id])) {
                    continue;
                }
                $processes[$id] ??= ['id' => $id, 'arguments' => self::nativeArguments($arguments[$id]), 'samples' => []];
                $processes[$id]['samples'][] = $process + ['t' => $sample['t'], 'uptime' => $sample['uptime']];
            }
        }
        return $processes;
    }

    /**
     * Strip the observation wrapper and its progress options so classification
     * sees the arguments the application passed.
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    public static function nativeArguments(array $arguments): array
    {
        if (str_ends_with($arguments[1] ?? '', '/ffmpeg') || str_ends_with($arguments[1] ?? '', 'media-control-ffmpeg.php')) {
            $arguments = ['ffmpeg', ...array_slice($arguments, 2)];
        }
        if (($arguments[1] ?? null) === '-progress' && ($arguments[3] ?? null) === '-stats_period') {
            array_splice($arguments, 1, 4);
        }
        return $arguments;
    }

    /**
     * @param list<string> $arguments
     * @return array{kind:string,codec:?string}|null
     */
    public static function nativeKind(array $arguments): ?array
    {
        if (basename($arguments[0] ?? '') !== 'ffmpeg') {
            return null;
        }
        $option = static function (string $name) use ($arguments): ?string {
            $index = array_search($name, $arguments, true);
            return $index === false ? null : ($arguments[$index + 1] ?? null);
        };
        if ($option('-f') === 'dash' || in_array('dash', $arguments, true)) {
            return $option('-c') === 'copy' ? ['kind' => 'video-copy', 'codec' => null] : ['kind' => 'video-encode', 'codec' => $option('-c:v')];
        }
        $codec = $option('-c:a');
        if ($codec !== null && array_filter($arguments, static fn(string $value): bool => str_contains($value, '/audio-renditions/')) !== []) {
            return ['kind' => $codec === 'copy' ? 'audio-copy' : 'audio-encode', 'codec' => $codec];
        }
        return ['kind' => 'native-helper', 'codec' => null];
    }

    /**
     * @param array{samples:list<array<string,mixed>>} $process
     */
    private static function overlaps(array $process, float $start, float $end): bool
    {
        return $process['samples'][0]['t'] <= $end && $start <= $process['samples'][array_key_last($process['samples'])]['t'];
    }

    /**
     * @param array{id:string,samples:list<array<string,mixed>>} $process
     * @param array{kind:string,codec:?string} $kind
     * @param array<string,mixed> $sources
     * @return array<string,mixed>
     */
    public static function nativeCost(array $process, array $kind, array $sources): array
    {
        $samples = $process['samples'];
        $output = static function (array $sample) use ($kind, $sources): ?float {
            $progress = $sample['progress'] ?? [];
            if ($kind['kind'] === 'video-encode' && isset($progress['frame'])) {
                return $progress['frame'] / Reports::number($sources['videoFrameRate']);
            }
            return isset($progress['out_time_us']) ? $progress['out_time_us'] / 1e6 : null;
        };
        $last = $samples[array_key_last($samples)];
        $cost = self::memory($process) + [
            'lifetimeSeconds' => round($last['uptime'] - $last['startTicks'] / self::CLOCK_TICKS, 3),
            'cpuSeconds' => round($last['cpuTicks'] / self::CLOCK_TICKS, 2),
            'outputSeconds' => ($final = $output($last)) === null ? null : round($final, 3),
        ];
        if ($final === null && in_array($kind['kind'], ['video-copy', 'audio-encode', 'audio-copy'], true)) {
            // A job that ended between progress reports ran its whole source.
            $cost['outputSeconds'] = (float) Reports::number($kind['kind'] === 'video-copy' ? $sources['videoSeconds'] : $sources['trackSeconds']);
            $cost['outputAssumedComplete'] = true;
        }
        // The steady window starts after warm-up and ends at the last sample whose
        // output advanced, before a buffer-ahead throttle or cancellation stops it.
        $first = $end = null;
        foreach ($samples as $sample) {
            $value = $output($sample);
            if ($value === null) {
                continue;
            }
            if ($first === null && $value >= self::WARM_UP_OUTPUT_SECONDS) {
                $first = $sample;
            }
            if ($end === null || $value > $output($end)) {
                $end = $sample;
            }
        }
        if ($first !== null && $end !== null && $end['t'] - $first['t'] >= 2.0) {
            $produced = $output($end) - $output($first);
            $wall = $end['t'] - $first['t'];
            $cpu = ($end['cpuTicks'] - $first['cpuTicks']) / self::CLOCK_TICKS;
            $cost['steady'] = [
                'wallSeconds' => round($wall, 3),
                'outputSeconds' => round($produced, 3),
                'speed' => round($produced / $wall, 3),
                'cpuSecondsPerOutputSecond' => round($cpu / $produced, 4),
                'cpus' => round($cpu / $wall, 3),
                'threadCpus' => self::threadCpus(array_filter($samples, static fn(array $sample): bool => $first['t'] <= $sample['t'] && $sample['t'] <= $end['t']), $wall),
            ];
        } elseif ($end !== null && $output($end) >= self::WARM_UP_OUTPUT_SECONDS) {
            // Short jobs report figures from process start to their last advance; startup is included.
            $wall = $end['uptime'] - $end['startTicks'] / self::CLOCK_TICKS;
            $cost['whole'] = [
                'speed' => round($output($end) / max($wall, .01), 3),
                'cpuSecondsPerOutputSecond' => round($end['cpuTicks'] / self::CLOCK_TICKS / $output($end), 4),
            ];
        } elseif ($cost['outputSeconds'] !== null && $cost['outputSeconds'] >= self::WARM_UP_OUTPUT_SECONDS && $cost['lifetimeSeconds'] > 0) {
            // A job that exited before reporting progress ran its whole source within its lifetime.
            $cost['whole'] = [
                'speed' => round($cost['outputSeconds'] / $cost['lifetimeSeconds'], 3),
                'cpuSecondsPerOutputSecond' => round($cost['cpuSeconds'] / $cost['outputSeconds'], 4),
            ];
        }
        return $cost;
    }

    /**
     * Mean and 95th-percentile CPUs between consecutive samples, and anonymous
     * versus page-cache memory, for each sampled service container.
     *
     * @param list<array<string,mixed>> $window
     * @return array<string,array<string,float>>
     */
    public static function services(array $window): array
    {
        $services = [];
        foreach (array_keys($window[0]['containers'] ?? []) as $role) {
            $rates = [];
            foreach (array_slice($window, 1, null, true) as $index => $sample) {
                $previous = $window[$index - 1];
                $elapsed = $sample['t'] - $previous['t'];
                if ($elapsed > 0 && isset($sample['containers'][$role]['usageUsec'], $previous['containers'][$role]['usageUsec'])) {
                    $rates[] = ($sample['containers'][$role]['usageUsec'] - $previous['containers'][$role]['usageUsec']) / 1e6 / $elapsed;
                }
            }
            if ($rates === []) {
                continue;
            }
            sort($rates);
            $last = $window[array_key_last($window)];
            $anon = array_map(static fn(array $sample): int => (int) ($sample['containers'][$role]['anonBytes'] ?? 0), $window);
            $file = array_map(static fn(array $sample): int => (int) ($sample['containers'][$role]['fileBytes'] ?? 0), $window);
            $services[$role] = [
                'meanCpus' => round(($last['containers'][$role]['usageUsec'] - $window[0]['containers'][$role]['usageUsec']) / 1e6 / ($last['t'] - $window[0]['t']), 3),
                'p95Cpus' => round($rates[(int) ceil(.95 * count($rates)) - 1], 3),
                'meanAnonMiB' => round(array_sum($anon) / count($anon) / 1048576, 1),
                'peakAnonMiB' => round(max($anon) / 1048576, 1),
                'peakFileMiB' => round(max($file) / 1048576, 1),
            ];
        }
        return $services;
    }

    /**
     * CPUs each thread used across the window, busiest first. A thread's last
     * reading counts even when it exited before the window ended.
     *
     * @param array<array-key,array<string,mixed>> $window
     * @return list<float>
     */
    private static function threadCpus(array $window, float $wall): array
    {
        $first = reset($window);
        $last = [];
        foreach ($window as $sample) {
            foreach ($sample['threadTicks'] ?? [] as $thread => $ticks) {
                $last[$thread] = $ticks;
            }
        }
        $cpus = [];
        foreach ($last as $thread => $ticks) {
            $cpus[] = round(($ticks - ($first['threadTicks'][$thread] ?? 0)) / self::CLOCK_TICKS / $wall, 2);
        }
        rsort($cpus);
        return $cpus;
    }

    /**
     * @param array{samples:list<array<string,mixed>>} $process
     * @return array{peakRssMiB:float,peakThreads:int,samples:int}
     */
    private static function memory(array $process): array
    {
        $hwm = max(array_map(static fn(array $sample): int => (int) ($sample['hwmKib'] ?? $sample['rssKib'] ?? 0), $process['samples']));
        return [
            'peakRssMiB' => round($hwm / 1024, 1),
            'peakThreads' => max(array_column($process['samples'], 'threads')),
            'samples' => count($process['samples']),
        ];
    }

    /**
     * @param array{id:string,arguments:list<string>,samples:list<array<string,mixed>>} $process
     * @param array<string,array{id:string,arguments:list<string>,samples:list<array<string,mixed>>}> $all
     * @return array{role:string,kind:string}|null
     */
    public static function childRole(array $process, array $all): ?array
    {
        $script = array_search(true, array_map(static fn(string $value): bool => str_ends_with($value, '/bin/worker-media-job.php'), $process['arguments']), true);
        if ($script === false) {
            return null;
        }
        $kind = $process['arguments'][$script + 1] ?? 'unknown';
        $parent = (int) $process['samples'][0]['ppid'];
        foreach ($all as $other) {
            if ($other['id'] !== $process['id'] && (int) explode(':', $other['id'])[0] === $parent && $other['arguments'] === $process['arguments']) {
                return ['role' => 'helper', 'kind' => $kind];
            }
        }
        return ['role' => 'job', 'kind' => $kind];
    }

    /**
     * @param list<array<string,mixed>> $window
     * @param array<string,array{id:string,arguments:list<string>,samples:list<array<string,mixed>>}> $native
     * @return array<string,int>
     */
    private static function concurrency(array $window, array $native): array
    {
        $kinds = [];
        foreach ($native as $id => $process) {
            $kinds[$id] = self::nativeKind($process['arguments'])['kind'] ?? null;
        }
        $maximum = [];
        foreach ($window as $sample) {
            $counts = [];
            foreach ($sample['processes'] as $process) {
                $kind = $kinds[$process['id']] ?? null;
                if ($kind !== null) {
                    $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                }
            }
            foreach ($counts as $kind => $count) {
                $maximum[$kind] = max($maximum[$kind] ?? 0, $count);
            }
        }
        ksort($maximum);
        return $maximum;
    }

    /**
     * @param list<array<string,mixed>> $window
     * @return array<string,mixed>|null
     */
    private static function unit(array $window): ?array
    {
        if (count($window) < 2) {
            return null;
        }
        $first = $window[0]['cgroup'];
        $last = $window[array_key_last($window)]['cgroup'];
        $wall = $window[array_key_last($window)]['t'] - $window[0]['t'];
        return [
            'averageCpus' => $wall > 0 ? round(($last['usageUsec'] - $first['usageUsec']) / 1e6 / $wall, 3) : null,
            'throttledSeconds' => round(($last['throttledUsec'] - $first['throttledUsec']) / 1e6, 3),
            'peakMemoryCurrentMiB' => round(max(array_map(static fn(array $sample): int => (int) $sample['cgroup']['memoryCurrent'], $window)) / 1048576, 1),
            'peakPids' => max(array_map(static fn(array $sample): int => (int) $sample['cgroup']['pidsCurrent'], $window)),
        ];
    }

    /**
     * Per job kind and codec, the largest peak memory and threads and the
     * steady-state CPU and speed figures of every measured job, isolated and concurrent.
     *
     * @param list<array<string,mixed>> $phases
     * @return array<string,mixed>
     */
    public static function summary(array $phases): array
    {
        $native = $children = $services = [];
        foreach ($phases as $phase) {
            if (($phase['services'] ?? []) !== []) {
                $services[$phase['name']] = $phase['services'];
            }
            $mode = match (true) {
                str_starts_with($phase['name'], 'isolated-') => 'isolated',
                str_starts_with($phase['name'], 'replay-') => 'replay',
                default => 'concurrent',
            };
            foreach ($phase['native'] as $job) {
                if ($job['kind'] === 'native-helper') {
                    continue;
                }
                $key = $job['kind'] . ($job['codec'] === null ? '' : ':' . $job['codec']);
                $entry = $native[$key][$mode] ?? ['peakRssMiB' => 0, 'peakThreads' => 0, 'cpuSecondsPerOutputSecond' => [], 'speed' => []];
                $entry['peakRssMiB'] = max($entry['peakRssMiB'], $job['peakRssMiB']);
                $entry['peakThreads'] = max($entry['peakThreads'], $job['peakThreads']);
                $figures = $job['steady'] ?? $job['whole'] ?? null;
                if ($figures !== null) {
                    $entry['cpuSecondsPerOutputSecond'][] = $figures['cpuSecondsPerOutputSecond'];
                    $entry['speed'][] = $figures['speed'];
                }
                $native[$key][$mode] = $entry;
            }
            foreach ($phase['mediaChildren'] as $child) {
                $key = $child['role'] . ':' . $child['kind'];
                $children[$key] = [
                    'peakRssMiB' => max($children[$key]['peakRssMiB'] ?? 0, $child['peakRssMiB']),
                    'peakThreads' => max($children[$key]['peakThreads'] ?? 0, $child['peakThreads']),
                ];
            }
        }
        ksort($native);
        ksort($children);
        return ['mediaChildren' => $children, 'native' => $native, 'services' => $services];
    }
}
