<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use InvalidArgumentException;

final class MediaControlVerifier
{
    /** @return array<string,mixed> */
    public static function verify(string $root): array
    {
        $media = Reports::json($root . '/media.json')['evidence'];
        $before = Reports::json($root . '/containers-before.json');
        $after = Reports::json($root . '/containers-after.json');
        Reports::require(
            array_column(array_slice($before, 0, 2), 'Id') === array_column($after, 'Id'),
            'Container identities changed',
        );
        Reports::require(
            array_all(
                $after,
                static fn(array $unit): bool => $unit['State']['Running'] && ($unit['RestartCount'] ?? 0) === 0,
            ),
            'Container stopped or restarted',
        );
        [$control, $execution] = $before;
        foreach ($before as $unit) {
            $config = $unit['HostConfig'];
            Reports::require(
                $config['Memory'] > 0 && $config['PidsLimit'] > 0 && $config['NanoCpus'] > 0,
                'Unbounded container capacity',
            );
        }
        Reports::require(
            array_intersect(
                explode(',', $control['HostConfig']['CpusetCpus']),
                explode(
                    ',',
                    $execution['HostConfig']['CpusetCpus'],
                ),
            ) === [],
            'Control and execution CPU sets overlap',
        );
        $start = $media['pressureStartedAt'];
        $end = $media['pressureEndedAt'];
        Reports::require($end - $start >= 60, 'Missing sixty-second pressure period');
        $controls = $media['desiredStateSamples'];
        Reports::require(count($controls) >= 40, 'Missing desired-state samples');
        Reports::require(
            array_all(
                $controls,
                static fn(array $sample): bool => $sample['observedRevision'] >= $sample['desiredRevision'],
            ),
            'Desired state not acknowledged',
        );
        $report = [
            'controlAcknowledgement' => Reports::bounds(array_column($controls, 'seconds')),
            'seekHandlingSeconds' => $media['seekHandlingSeconds'],
            'cancelHandlingSeconds' => $media['cancelHandlingSeconds'],
            'seekReadinessSeconds' => $media['seekReadinessSeconds'],
            'cancelRetirementSeconds' => $media['cancelRetirementSeconds'],
            'attachments' => $media['attachments'],
            'independentOwners' => $media['independentOwners'],
            'unaffectedOwnerJobs' => $media['unaffectedOwnerJobs'],
            'generations' => $media['generations'],
            'retiredOutputStatuses' => $media['retiredOutputStatuses'],
            'retainedOutputFiles' => $media['retainedOutputFiles'],
            'socketReconnectReplay' => $media['socketReconnectReplay'],
            'pendingCancellation' => $media['pendingCancellation'],
            'containerIds' => ['control' => $control['Id'], 'execution' => $execution['Id']],
        ];
        Reports::require(
            $report['controlAcknowledgement']['p99Seconds'] <= 1 && $report['controlAcknowledgement']['maxSeconds'] <= 2,
            'Control acknowledgement exceeded deadline',
        );
        Reports::require(
            max($media['seekHandlingSeconds'], $media['cancelHandlingSeconds']) <= 2,
            'Seek/cancel handling exceeded deadline',
        );
        Reports::require(
            $media['pendingCancellation']['outcome'] === 'cancelled' && $media['pendingCancellation']['nativeLaunches'] === 0 && $media['pendingCancellation']['references'] === 0,
            'Pending cancellation launched work or retained references',
        );
        Reports::require(
            $report['seekReadinessSeconds'] >= 0 && $report['seekReadinessSeconds'] <= 30 && $report['cancelRetirementSeconds'] >= 0 && $report['cancelRetirementSeconds'] <= 30,
            'Seek/cancel readiness exceeded deadline',
        );
        Reports::require($media['generations'][1] > $media['generations'][0], 'Seek did not advance generation');
        Reports::require(
            array_all(
                $media['retiredOutputStatuses'],
                static fn(int $status): bool => in_array(
                    $status,
                    [
                        403,
                        404,
                        410,
                    ],
                    true,
                ),
            ),
            'Retired output remained available',
        );
        Reports::require(
            $media['retainedOutputFiles'] >= 2 && $media['socketReconnectReplay'] === true,
            'Missing retained output or reconnect replay',
        );
        Reports::require(
            $media['independentOwners'] === 4 && count($media['unaffectedOwnerJobs']) === 2,
            'Independent owner work changed',
        );
        $phases = array_column($media['attachments'], 'phase')
            |> array_unique(...)
            |> array_values(...);
        sort($phases);
        Reports::require($phases === ['paused', 'running'], 'Missing running/paused attachments');
        Reports::require(
            array_all(
                $media['attachments'],
                static fn(array $entry): bool => $entry['references'] === 1,
            ),
            'Attachment reference count differs',
        );
        $report['sampling'] = [];
        foreach (['control', 'execution'] as $name) {
            $window = Reports::window(
                Reports::samples($root . '/' . $name . '-samples.jsonl', EvidenceMode::Completed),
                $start,
                $end,
            );
            Reports::require(count($window) >= 2, 'Missing ' . $name . ' pressure samples');
            $times = array_column($window, 'monotonic');
            $gaps = [];
            foreach (array_slice($times, 1) as $index => $time) {
                $gaps[] = $time - $times[$index];
            }
            Reports::require(min($gaps) > 0 && max($gaps) <= 2, 'Invalid pressure sampling cadence');
            Reports::require(
                $times[0] - $start <= 2 && $end - $times[array_key_last($times)] <= 2,
                'Truncated pressure sampling window',
            );
            $report['sampling'][$name] = ['count' => count($window), 'maximumGapSeconds' => max($gaps)];
            if ($name === 'execution') {
                $saturated = $cancellation = 0;
                foreach ($window as $sample) {
                    $stamp = $sample['monotonic'];
                    $videos = count(array_filter(
                        $sample['nativeProcesses'],
                        static fn(array $process): bool => in_array(
                            '/media/source/video.mkv',
                            $process['argv'],
                            true,
                        ),
                    ));
                    $audio = count(array_filter(
                        $sample['nativeProcesses'],
                        static fn(array $process): bool => in_array(
                            '/media/source/track.flac',
                            $process['argv'],
                            true,
                        ),
                    ));
                    Reports::require($audio >= 1, 'Admitted audio slot disappeared during controls');
                    if ($stamp < $media['seekStartedAt'] || ($media['seekReadyAt'] <= $stamp && $stamp < $media['cancelStartedAt'])) {
                        Reports::require($videos >= 3, 'Full video occupancy disappeared before cancellation');
                        ++$saturated;
                    } elseif ($media['cancelRetiredAt'] <= $stamp) {
                        Reports::require($videos >= 2, 'Unaffected video work disappeared after cancellation');
                        ++$cancellation;
                    }
                }
                Reports::require($cancellation > 0, 'Missing released-slot cancellation observation');
                Reports::require($saturated > 10, 'Insufficient saturated control samples');
                $report['fullOccupancySamples'] = $saturated;
                $report['postCancellationSamples'] = $cancellation;
                $cpuSeconds = (Reports::counters($window[array_key_last($window)]['cpu.stat'])['usage_usec'] - Reports::counters($window[0]['cpu.stat'])['usage_usec']) / 1e6;
                Reports::require(
                    $cpuSeconds >= ($times[array_key_last($times)] - $times[0]) * 1.5,
                    'Execution CPU pressure was not sustained',
                );
                $report['executionCpuSeconds'] = $cpuSeconds;
            } else {
                $initial = $window[0];
                $oom = Reports::counters($initial['memory.events'])['oom_kill'] ?? 0;
                Reports::require(
                    array_all(
                        $window,
                        static fn(array $sample): bool => (Reports::counters($sample['memory.events'])['oom_kill'] ?? 0) === $oom,
                    ),
                    'Control suffered OOM',
                );
                Reports::require(
                    array_all(
                        $window,
                        static fn(array $sample): bool => $sample['heartbeat']['bootId'] === $initial['heartbeat']['bootId']
                            && $sample['heartbeat']['epoch'] === $initial['heartbeat']['epoch'],
                    ),
                    'Control heartbeat authority changed',
                );
                Reports::require(
                    array_all(
                        $window,
                        static fn(array $sample): bool => $sample['lease']['owner_boot_id'] === $initial['lease']['owner_boot_id']
                            && $sample['lease']['epoch'] === $initial['lease']['epoch']
                            && $sample['lease']['state'] === 'active'
                            && Reports::truth($sample['lease']['unexpired']),
                    ),
                    'Lease continuity failed',
                );
                $heartbeat = Reports::bounds(array_map(
                    static fn(array $sample): float => $sample['monotonic'] - $sample['heartbeat']['time'],
                    $window,
                ));
                Reports::require(
                    $heartbeat['p99Seconds'] <= 1 && $heartbeat['maxSeconds'] <= 2,
                    'Heartbeat exceeded deadline',
                );
                $report['heartbeat'] = $heartbeat;
                Reports::require(
                    min(array_column($window, 'ordinaryReceiverDepth')) > 0,
                    'Ordinary backlog disappeared',
                );
            }
        }
        $backlog = Reports::window(Reports::samples($root . '/backlog.jsonl', EvidenceMode::Completed), $start, $end);
        Reports::require(
            count($backlog) > 1 && $backlog[array_key_last($backlog)]['acknowledged'] > $backlog[0]['acknowledged'],
            'Ordinary consumer made no progress',
        );
        $report['ordinaryBacklog'] = ['acknowledgedProgress' => $backlog[array_key_last($backlog)]['acknowledged'] - $backlog[0]['acknowledged']];
        $launches = Reports::samples($root . '/native-launches.jsonl', EvidenceMode::Completed);
        $seen = [];
        foreach ($launches as $launch) {
            $key = json_encode([$launch['jobId'], $launch['generation']], JSON_THROW_ON_ERROR);
            Reports::require(
                !isset($seen[$key]) && !$launch['predecessors'],
                'Duplicate or overlapping native launch',
            );
            $seen[$key] = true;
        }
        Reports::require(
            $launches !== [] && count($media['videoJobs']) === 3 && $media['audioBytes'] > 0,
            'Missing media launch evidence',
        );
        $report['nativeLaunches'] = count($launches);
        Reports::publish($root . '/qualification.json', $report);
        return $report;
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        if (count($arguments) !== 1) {
            throw new InvalidArgumentException('Usage: media-control-verify.php DIRECTORY');
        }
        echo json_encode(self::verify($arguments[0]), JSON_THROW_ON_ERROR), "\n";
        return 0;
    }
}
