<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class LoadSmoke
{
    /**
     * @param list<float> $samples
     */
    public static function percentile(array $samples, int $percentage): float
    {
        Runtime::requireThat($samples !== [], 'Latency samples are empty.');
        sort($samples, SORT_NUMERIC);
        return $samples[max(0, (int) ceil(count($samples) * $percentage / 100) - 1)];
    }
    public static function processRssBytes(Process $process): int
    {
        $match = [];
        $status = file_get_contents('/proc/' . $process->pid() . '/status');
        Runtime::requireThat(
            $status !== false && preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $match) === 1,
            'Registry API RSS is unavailable.',
        );
        return (int) $match[1] * 1024;
    }
    /**
     * @param list<Api> $apis
     * @param list<array<string,mixed>> $registrations
     * @return array<string,int|float>
     */
    public static function runPhase(array $apis, array $registrations, int $seconds, float $lookupRate, float $heartbeatRate): array
    {
        $schedule = [];
        foreach (['lookup' => $lookupRate, 'heartbeat' => $heartbeatRate] as $kind => $rate) {
            for ($index = 0; $index / $rate < $seconds; ++$index) {
                $schedule[] = [$index / $rate, $kind, $index];
            }
        }
        usort($schedule, static fn(array $left, array $right): int => $left <=> $right);
        $latencies = ['lookup' => [], 'heartbeat' => []];
        $pending = $maximumPending = 0;
        $maximumLag = 0.0;
        $failures = [];
        $slots = new Channel(24);
        for ($index = 0; $index < 24; ++$index) {
            $slots->push(true);
        }
        $start = Runtime::now();
        foreach ($schedule as [$due, $kind, $index]) {
            $remaining = $start + $due - Runtime::now();
            if ($remaining > 0) {
                Coroutine::sleep($remaining);
            }
            if ($failures !== []) {
                break;
            }
            $maximumLag = max($maximumLag, Runtime::now() - $start - $due);
            Runtime::requireThat($pending < 64, 'Registry workload generated an unbounded request backlog.');
            ++$pending;
            $maximumPending = max($maximumPending, $pending);
            Coroutine::create(static function () use ($apis, $registrations, $kind, $index, $slots, &$pending, &$latencies, &$failures): void {
                $slots->pop();
                try {
                    $api = $apis[$index % count($apis)];
                    $item = $registrations[$index % count($registrations)];
                    $started = Runtime::now();
                    [$status, $body] = $kind === 'lookup' ? $api->request('GET', '/api/servers/' . $item['publicId']) : $api->request('POST', '/api/servers/register', $item, false);
                    $latency = Runtime::now() - $started;
                    Runtime::requireThat(
                        $status === 200 && ($body['data']['publicId'] ?? null) === $item['publicId'],
                        "{$kind} failed for {$item['publicId']}: HTTP {$status}",
                    );
                    $latencies[$kind][] = $latency;
                } catch (\Throwable $failure) {
                    $failures[] = $failure;
                } finally {
                    --$pending;
                    $slots->push(true);
                }
            });
        }
        $deadline = Runtime::now() + 10;
        while ($pending > 0 && Runtime::now() < $deadline) {
            Coroutine::sleep(0.005);
        }
        Runtime::requireThat($pending === 0, 'Registry workload did not drain within ten seconds.');
        if ($failures !== []) {
            throw $failures[0];
        }
        Runtime::requireThat($maximumLag <= 1, sprintf('Load generator fell %.3fs behind schedule.', $maximumLag));
        return [
            'lookups' => count($latencies['lookup']),
            'heartbeats' => count($latencies['heartbeat']),
            'lookup_p95_ms' => round(self::percentile($latencies['lookup'], 95) * 1000, 2),
            'lookup_p99_ms' => round(self::percentile($latencies['lookup'], 99) * 1000, 2),
            'heartbeat_p95_ms' => round(self::percentile($latencies['heartbeat'], 95) * 1000, 2),
            'heartbeat_p99_ms' => round(self::percentile($latencies['heartbeat'], 99) * 1000, 2),
            'maximum_pending' => $maximumPending,
            'maximum_dispatch_lag_ms' => round($maximumLag * 1000, 2),
        ];
    }
    public static function run(string $rqlited, string $server, int $normalSeconds = 10, int $burstSeconds = 10): void
    {
        Runtime::requireThat(
            $normalSeconds >= 2 && $normalSeconds <= 300 && $burstSeconds >= 2 && $burstSeconds <= 300,
            'Workload phase duration must be between 2 and 300 seconds.',
        );
        $directory = Runtime::temporaryDirectory('baander-registry-load-');
        $cluster = null;
        $apis = [];
        try {
            $cluster = new Cluster($directory, $rqlited, 3);
            $cluster->start();
            for ($index = 0; $index < 3; ++$index) {
                $apis[] = new Api(
                    $cluster,
                    $server,
                    "load-api-{$index}",
                    array_map(static fn(int $node): array => [$node, $cluster->httpPorts[$node]], [0, 1, 2]),
                    8,
                );
            }
            $registrations = [];
            for ($index = 0; $index < 100; ++$index) {
                $registration = ClusterContract::registration(sprintf('load-%03d', $index));
                [$status, $body] = $apis[$index % count($apis)]->request('POST', '/api/servers/register', $registration);
                Runtime::requireThat($status === 200 && ($body['data']['revision'] ?? null) === 1, 'Initial registration failed.');
                $registrations[] = $registration;
            }
            foreach (array_slice($registrations, 0, 10) as $registration) {
                Runtime::requireThat($apis[0]->request('GET', '/api/servers/' . $registration['publicId'])[0] === 200, 'Registry warm-up lookup failed.');
            }
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
            $report = [];
            $peak = 0;
            \Swoole\Coroutine\run(static function () use ($apis, $registrations, $normalSeconds, $burstSeconds, &$report, &$peak): void {
                $sampling = new \stdClass();
                $sampling->active = true;
                $samplingFailures = [];
                $stopped = new Channel(1);
                Coroutine::create(static function () use ($apis, &$sampling, &$samplingFailures, &$peak, $stopped): void {
                    try {
                        while ($sampling->active) {
                            foreach ($apis as $api) {
                                $peak = max($peak, self::processRssBytes($api->process));
                            }
                            Coroutine::sleep(0.05);
                        }
                    } catch (\Throwable $failure) {
                        $samplingFailures[] = $failure;
                    } finally {
                        $stopped->push(true);
                    }
                });
                try {
                    $normal = self::runPhase($apis, $registrations, $normalSeconds, 20, 100 / 60);
                    $burst = self::runPhase($apis, $registrations, $burstSeconds, 100, 500 / 60);
                } finally {
                    $sampling->active = false;
                    Runtime::requireThat($stopped->pop(2) !== false, 'Registry API memory sampler did not stop.');
                }
                Runtime::requireThat($samplingFailures === [], 'Registry API memory sampling failed.');
                $report = [
                    'normal' => $normal,
                    'burst' => $burst,
                    'api_peak_rss_mib' => round($peak / 1024 / 1024, 2),
                ];
            });
            echo json_encode($report, JSON_THROW_ON_ERROR) . "\n";
            foreach (['lookup_p95_ms', 'heartbeat_p95_ms'] as $key) {
                Runtime::requireThat($report['normal'][$key] <= 1000, 'Normal latency exceeded release threshold.');
            }
            foreach (['lookup_p99_ms', 'heartbeat_p99_ms'] as $key) {
                Runtime::requireThat(
                    $report['normal'][$key] <= 2000 && $report['burst'][$key] <= 3000,
                    'Tail latency exceeded release threshold.',
                );
            }
            Runtime::requireThat($peak <= 64 * 1024 * 1024, 'Registry API exceeded 64 MiB peak sampled RSS.');
            echo "PASS: bounded three-voter registry workload smoke.\n";
        } finally {
            try {
                Runtime::closeResources([...$apis, $cluster]);
            } finally {
                Runtime::removeTree($directory);
            }
        }
    }
}
