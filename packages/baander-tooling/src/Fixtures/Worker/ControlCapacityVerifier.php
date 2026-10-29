<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use InvalidArgumentException;

final class ControlCapacityVerifier
{
    /** @return array<string,mixed> */
    public static function exhausted(string $root, string $phase, bool $final = false): array
    {
        $execution = Reports::samples($root . '/execution-samples.jsonl', $final ? EvidenceMode::Completed : EvidenceMode::Live);
        [$key, $event] = $phase === 'memory' ? ['memory.events', 'oom_kill'] : ['pids.events', 'max'];
        Reports::require(count($execution) > 1, 'Missing execution cgroup samples');
        $baseline = Reports::counters($execution[0][$key])[$event] ?? 0;
        $latest = max(array_map(
            static fn(array $sample): int => Reports::counters($sample[$key])[$event] ?? 0,
            $execution,
        ));
        if ($latest > $baseline) {
            return [
                'source' => 'execution-cgroup-counter',
                'counter' => $event,
                'baseline' => $baseline,
                'maximum' => $latest,
            ];
        }
        $before = Reports::json($root . '/containers-before.json');
        if ($phase === 'pid') {
            $pressure = is_file($root . '/pressure.log') ? Reports::samples($root . '/pressure.log', $final ? EvidenceMode::Completed : EvidenceMode::Live) : [];
            $configured = $before[1]['HostConfig']['PidsLimit'];
            Reports::require(
                Reports::integer($execution[0]['pids.max']) === $configured,
                'Sampled execution PID ceiling differs from configured limit',
            );
            foreach ($pressure as $entry) {
                $kernel = $entry['executionCgroup'] ?? null;
                if (($entry['forkRefused'] ?? null) !== true
                    || !is_array($kernel)
                    || ($kernel['cgroup'] ?? null) !== $execution[0]['cgroup']
                    || Reports::integer($kernel['pids.max']) !== $configured
                ) {
                    continue;
                }
                $observed = Reports::counters($kernel['pids.events'])['max'] ?? 0;
                if ($observed <= $baseline) {
                    continue;
                }
                Reports::require(
                    $kernel['monotonic'] >= $execution[0]['monotonic'],
                    'PID refusal kernel evidence predates execution baseline',
                );
                $currentPids = Reports::integer($kernel['pids.current']);
                Reports::require(
                    $currentPids > 0 && $currentPids <= $configured,
                    'Invalid contemporaneous execution PID count',
                );
                return [
                    'source' => 'execution-pressure-process-kernel-counter',
                    'counter' => 'max',
                    'baseline' => $baseline,
                    'observed' => $observed,
                    'executionCgroup' => $kernel,
                    'configuredPidsLimit' => $configured,
                ];
            }
            throw new \RuntimeException('No increased execution PID max counter observed by sampler or refusing process');
        }
        Reports::require($phase === 'memory', 'No execution ' . $event . ' event observed');
        $identity = $before[1]['Id'];
        $eventPath = $root . ($final ? '/worker-oom-events-final.jsonl' : '/worker-oom-events.jsonl');
        $events = is_file($eventPath) ? Reports::samples($eventPath, $final ? EvidenceMode::Completed : EvidenceMode::Live) : [];
        $matches = array_filter(
            $events,
            static fn(array $entry): bool => self::oomEvent(
                $entry,
                $identity,
            ),
        )
            |> array_values(...);
        Reports::require(
            $matches !== [],
            'Neither execution oom_kill counter nor exact-container Docker OOM event was observed',
        );
        $memoryMax = Reports::integer($execution[0]['memory.max']);
        Reports::require(
            $memoryMax === $before[1]['HostConfig']['Memory'],
            'Observed execution memory ceiling differs from configured limit',
        );
        $maxBaseline = Reports::counters($execution[0]['memory.events'])['max'] ?? 0;
        $maxEvents = max(array_map(
            static fn(array $sample): int => Reports::counters($sample['memory.events'])['max'] ?? 0,
            $execution,
        ));
        $peak = max(array_map(
            static fn(array $sample): int => Reports::integer(($sample['memory.peak'] ?? null) ?: $sample['memory.current']),
            $execution,
        ));
        Reports::require(
            $maxEvents > $maxBaseline,
            'Docker OOM event lacks observed execution memory-ceiling charge failures',
        );
        return [
            'source' => 'exact-container-docker-oom-event',
            'containerId' => $identity,
            'events' => $matches,
            'memoryLimitBytes' => $memoryMax,
            'observedPeakBytes' => $peak,
            'memoryMaxEventsBaseline' => $maxBaseline,
            'memoryMaxEventsMaximum' => $maxEvents,
        ];
    }

    /** @param array<string,mixed> $event */
    private static function oomEvent(array $event, string $identity): bool
    {
        return ($event['Type'] ?? null) === 'container'
            && ($event['Action'] ?? $event['status'] ?? null) === 'oom'
            && ($event['Actor']['ID'] ?? $event['id'] ?? null) === $identity;
    }

    /** @return array<string,mixed> */
    public static function verify(string $root, string $phase): array
    {
        [$control, $execution, $postgres, $redis] = Reports::json($root . '/containers-before.json');
        Reports::require(
            is_file($root . '/worker-oom-events-capture.json'),
            'Missing successful bounded Docker OOM event capture receipt',
        );
        $capture = Reports::json($root . '/worker-oom-events-capture.json');
        Reports::require(
            $capture['containerIds'] == [
                'control' => $control['Id'],
                'execution' => $execution['Id'],
            ],
            'Docker event capture used different container identities',
        );
        Reports::require(
            $capture['captureExitCode'] === 0 && $capture['monitorAliveAfterDrainAndCapture'] === true,
            'Docker event observation ended before drain evidence was complete',
        );
        Reports::require(
            $capture['monitorIntentionallyStopped'] === true && $capture['monitorExitCode'] === 143,
            'Docker event subscription did not stop under test ownership',
        );
        Reports::require(
            $capture['sinceSource'] === 'daemon-info-before-pressure' && $capture['untilSource'] === 'daemon-info-after-drain',
            'Docker event interval did not cover pressure through drain',
        );
        Reports::require(
            $capture['sinceUnixSeconds'] < $capture['untilUnixSeconds'],
            'Invalid bounded Docker event interval',
        );
        Reports::require(
            is_file($root . '/worker-oom-events-final.jsonl') && is_file($root . '/worker-oom-events.jsonl'),
            'Missing Docker OOM event capture output',
        );
        $events = [
            ...Reports::samples($root . '/worker-oom-events-final.jsonl'),
            ...Reports::samples($root . '/worker-oom-events.jsonl'),
        ];
        Reports::require(
            !array_any(
                $events,
                static fn(array $event): bool => self::oomEvent(
                    $event,
                    $control['Id'],
                ),
            ),
            'Control container emitted Docker OOM event',
        );
        foreach ([[$control, 2944, 129, 1_000_000_000], [$execution, 4480, 256, 2_000_000_000]] as [
            $unit,
            $memory,
            $pids,
            $cpu,
        ]) {
            $config = $unit['HostConfig'];
            Reports::require(
                $config['Memory'] === $memory * 1024 * 1024 && $config['PidsLimit'] === $pids && $config['NanoCpus'] === $cpu,
                'Container capacity differs from qualification recipe',
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
        foreach ([$postgres, $redis] as $unit) {
            Reports::require(
                $unit['HostConfig']['Memory'] > 0 && $unit['HostConfig']['PidsLimit'] > 0 && $unit['HostConfig']['NanoCpus'] > 0,
                'Unbounded dependency capacity',
            );
        }
        $samples = Reports::samples($root . '/control-samples.jsonl');
        Reports::require(count($samples) > 10, 'Insufficient control samples');
        $initial = $samples[0];
        $baseline = Reports::counters($initial['memory.events'])['oom_kill'] ?? 0;
        Reports::require(
            array_all(
                $samples,
                static fn(array $sample): bool => (Reports::counters($sample['memory.events'])['oom_kill'] ?? 0) === $baseline,
            ),
            'Control suffered OOM kill',
        );
        $preDrain = [];
        foreach ($samples as $sample) {
            if ($sample['heartbeat']['draining']) {
                break;
            }
            $preDrain[] = $sample;
        }
        Reports::require($preDrain !== [], 'No active control heartbeat samples');
        Reports::require(
            array_all(
                $preDrain,
                static fn(array $sample): bool => $sample['heartbeat']['epoch'] === $initial['heartbeat']['epoch']
                    && $sample['heartbeat']['bootId'] === $initial['heartbeat']['bootId'],
            ),
            'Control heartbeat authority changed',
        );
        Reports::require(
            array_all(
                $preDrain,
                static fn(array $sample): bool => $sample['lease']['epoch'] === $initial['lease']['epoch']
                    && $sample['lease']['owner_boot_id'] === $initial['lease']['owner_boot_id']
                    && $sample['lease']['state'] === 'active'
                    && Reports::truth($sample['lease']['unexpired']),
            ),
            'Lease continuity failed',
        );
        $ages = array_map(
            static fn(array $sample): float => $sample['monotonic'] - $sample['heartbeat']['time'],
            $preDrain,
        );
        Reports::require(min($ages) >= -.25, 'Invalid monotonic heartbeat clock');
        $report = [
            'controlOomProof' => [
                'cgroupOomKillCounterUnchanged' => true,
                'exactContainerDockerOomEvents' => 0,
                'boundedEventCapture' => $capture,
            ],
            'phase' => $phase,
            'heartbeat' => Reports::bounds($ages),
            'drainSeconds' => Reports::integer(Reports::text($root . '/drain-seconds.txt')),
            'containerIds' => ['control' => $control['Id'], 'execution' => $execution['Id']],
        ];
        Reports::require($report['drainSeconds'] <= 35, 'Pair exceeded drain deadline');
        $final = Reports::json($root . '/drain.json');
        Reports::require(
            array_column($final, 'Id') === [$control['Id'], $execution['Id']],
            'Container identity changed during qualification',
        );
        Reports::require(
            array_all($final, static fn(array $unit): bool => ($unit['RestartCount'] ?? 0) === 0),
            'A failed unit automatically restarted',
        );
        if ($phase === 'cpu') {
            $report += self::cpu($root, $preDrain, $initial);
            foreach ([$report['heartbeat'], $report['desiredState']] as $timing) {
                Reports::require(
                    $timing['p99Seconds'] <= 1 && $timing['maxSeconds'] <= 2,
                    'Control timing exceeded deadline',
                );
            }
        } else {
            $report['exhaustion'] = self::exhausted($root, $phase, true);
            $after = Reports::json($root . '/containers-after-pressure.json');
            Reports::require(!$after[0]['State']['OOMKilled'], 'Control OOM during execution exhaustion');
            $failures = Reports::samples($root . '/owned-failure.jsonl');
            $terminal = array_filter(
                $failures,
                static fn(array $sample): bool => ($sample['ownedTerminal']['state'] ?? null) === 'exited' && ($sample['ownedTerminal']['exitCode'] ?? 0) !== 0,
            )
                |> array_values(...);
            $uncertain = array_filter(
                $failures,
                static fn(array $sample): bool => array_key_exists(
                    'nativeFailure',
                    $sample,
                ),
            )
                |> array_values(...);
            Reports::require($terminal !== [] || $uncertain !== [], 'No actual owned execution failure observed');
            $trigger = trim(Reports::text($root . '/drain-trigger.txt'));
            Reports::require(
                $trigger === 'automatic-execution-exhaustion',
                'Exhaustion must cause automatic joint drain',
            );
            Reports::require(
                array_all($final, static fn(array $unit): bool => !$unit['State']['Running']),
                'Automatic pair drain missing',
            );
            $pressure = Reports::samples($root . '/pressure.log');
            if ($phase === 'memory') {
                $targets = array_filter(
                    $pressure,
                    static fn(array $sample): bool => array_key_exists(
                        'oomTarget',
                        $sample,
                    ),
                )
                    |> array_values(...);
                $deaths = array_filter(
                    $pressure,
                    static fn(array $sample): bool => array_key_exists(
                        'oomTargetDied',
                        $sample,
                    ),
                )
                    |> array_values(...);
                Reports::require(
                    count($targets) === 1 && $deaths !== [] && $targets[0]['oomTarget'] == $deaths[0]['oomTargetDied'],
                    'Selected exact native role death was not observed after OOM',
                );
                $report['executionOomTarget'] = $targets[0];
            } else {
                Reports::require(
                    array_any(
                        $pressure,
                        static fn(array $sample): bool => (bool) ($sample['forkRefused'] ?? false),
                    ),
                    'PID fork refusal missing',
                );
            }
            $report['ownedFailures'] = [...$terminal, ...$uncertain];
            $report['drainTrigger'] = $trigger;
            $report['controlSurvivedExecutionExhaustion'] = !$after[0]['State']['OOMKilled'];
        }
        Reports::publish($root . '/qualification.json', $report);
        return $report;
    }

    /** @param list<array<string,mixed>> $preDrain
     * @param array<string,mixed> $initial
     * @return array<string,mixed>
     */
    private static function cpu(string $root, array $preDrain, array $initial): array
    {
        Reports::require(
            $preDrain[array_key_last($preDrain)]['monotonic'] - $preDrain[0]['monotonic'] >= 60,
            'Missing sixty-second pressure window',
        );
        $media = Reports::json($root . '/media.json')['evidence'];
        $report = [
            'desiredState' => Reports::bounds(array_column($media['desiredStateSamples'], 'seconds')),
            'sampling' => [],
        ];
        $start = $media['pressureStartedAt'];
        $end = $media['pressureEndedAt'];
        Reports::require($end - $start >= 60, 'Pressure window lasted less than sixty seconds');
        $execution = Reports::samples($root . '/execution-samples.jsonl');
        foreach (['control' => $preDrain, 'execution' => $execution] as $name => $source) {
            $window = Reports::window($source, $start, $end);
            Reports::require(count($window) >= 2, 'Missing ' . $name . ' pressure-window samples');
            $times = array_column($window, 'monotonic');
            $gaps = [];
            foreach (array_slice($times, 1) as $index => $time) {
                $gaps[] = $time - $times[$index];
            }
            Reports::require(min($gaps) > 0, 'Non-increasing ' . $name . ' sample clock');
            $firstGap = $times[0] - $start;
            $lastGap = $end - $times[array_key_last($times)];
            $maximumGap = max($gaps);
            $report['sampling'][$name] = [
                'count' => count($times),
                'maxAdjacentGapSeconds' => $maximumGap,
                'startBoundaryGapSeconds' => $firstGap,
                'endBoundaryGapSeconds' => $lastGap,
            ];
            Reports::require($maximumGap <= 2, $name . ' sampling gap exceeded two seconds');
            Reports::require(
                $firstGap <= 2 && $lastGap <= 2,
                $name . ' samples did not cover both pressure boundaries',
            );
        }
        $backlogSamples = Reports::window($preDrain, $start, $end);
        Reports::require(
            $backlogSamples !== [] && min(array_column($backlogSamples, 'ordinaryReceiverDepth')) > 0,
            'Ordinary receiver backlog disappeared during pressure',
        );
        $backlog = $media['ordinaryBacklog'];
        Reports::require(
            $backlog[array_key_last($backlog)]['acknowledged'] > $backlog[0]['acknowledged'],
            'Ordinary consumer made no acknowledged progress',
        );
        Reports::require(
            $backlog[array_key_last($backlog)]['relayedEvents'] > $backlog[0]['relayedEvents'],
            'Outbox relay made no progress',
        );
        for ($offset = 0; $offset < 50; $offset += 10) {
            $window = array_filter(
                $backlog,
                static fn(array $sample): bool => $start + $offset <= $sample['monotonic'] && $sample['monotonic'] < $start + $offset + 10,
            )
                |> array_values(...);
            Reports::require(
                count($window) > 1 && $window[array_key_last($window)]['acknowledged'] > $window[0]['acknowledged'],
                'Ordinary consumer stalled for ten seconds',
            );
        }
        Reports::require(count($media['dueOccurrences']) >= 4, 'Missing due scheduler occurrences');
        foreach ($media['dueOccurrences'] as $occurrence) {
            $receipt = $occurrence['execution'];
            Reports::require(
                !empty($receipt) && Reports::truth($receipt['returned']),
                'Due scheduler occurrence did not return under ordinary backlog',
            );
            Reports::require(
                $receipt['deployment_boot_id'] === $initial['lease']['owner_boot_id'] && $receipt['deployment_epoch'] === $initial['lease']['epoch'],
                'Scheduler occurrence used different lease authority',
            );
        }
        $report['ordinaryBacklog'] = [
            'minimumReceiverDepth' => min(array_column($backlogSamples, 'ordinaryReceiverDepth')),
            'acknowledgedProgress' => $backlog[array_key_last($backlog)]['acknowledged'] - $backlog[0]['acknowledged'],
            'dueOccurrencesReturned' => count($media['dueOccurrences']),
        ];
        Reports::require(count($media['videoJobs']) === 3 && $media['audioBytes'] > 0, 'Missing admitted media');
        $occupied = Reports::window($execution, $start, $end);
        Reports::require(
            $occupied !== [] && $occupied[array_key_last($occupied)]['monotonic'] - $occupied[0]['monotonic'] >= 59,
            'Missing occupied native interval',
        );
        foreach ($occupied as $sample) {
            $processes = $sample['nativeProcesses'];
            Reports::require(
                count(array_filter(
                    $processes,
                    static fn(array $process): bool => in_array(
                        '/media/source/video.mkv',
                        $process['argv'],
                        true,
                    ),
                )) >= 3,
                'Three video native slots did not stay occupied',
            );
            Reports::require(
                count(array_filter(
                    $processes,
                    static fn(array $process): bool => in_array(
                        '/media/source/track.flac',
                        $process['argv'],
                        true,
                    ),
                )) >= 1,
                'Audio native slot did not stay occupied',
            );
        }
        $report['occupiedSlotSamples'] = count($occupied);
        $report['audioPressureMethod'] = 'Admitted FFmpeg process held with SIGSTOP; resumed after pressure';
        $usage = Reports::counters($execution[array_key_last($execution)]['cpu.stat'])['usage_usec'] - Reports::counters($execution[0]['cpu.stat'])['usage_usec'];
        $report['executionCpuSeconds'] = $usage / 1e6;
        Reports::require($usage >= 100_000_000, 'Execution did not sustain CPU pressure on its two CPUs');
        return $report;
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        if (count($arguments) !== 3 || !in_array($arguments[0], ['exhausted', 'verify'], true) || !in_array(
            $arguments[2],
            [
                'cpu',
                'memory',
                'pid',
            ],
            true,
        )) {
            throw new InvalidArgumentException('Usage: control-capacity-verify.php exhausted|verify DIRECTORY cpu|memory|pid');
        }
        if ($arguments[0] === 'exhausted') {
            self::exhausted($arguments[1], $arguments[2]);
        } else {
            echo json_encode(self::verify($arguments[1], $arguments[2]), JSON_THROW_ON_ERROR), "\n";
        }
        return 0;
    }
}
