<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use InvalidArgumentException;

final class ControlCapacityVerifier
{
    /**
     * Evidence that the native-loss phase ended the exact native role: one recorded target, its
     * observed death and the execution unit's kill order just before the role ended.
     *
     * @return array<string,mixed>
     */
    public static function nativeLost(string $root, bool $final = false): array
    {
        $path = $root . '/pressure.log';
        $pressure = is_file($path) ? Reports::samples($path, $final ? EvidenceMode::Completed : EvidenceMode::Live) : [];
        $targets = array_values(array_filter($pressure, static fn(array $row): bool => array_key_exists('nativeTarget', $row)));
        $deaths = array_values(array_filter($pressure, static fn(array $row): bool => array_key_exists('nativeDied', $row)));
        Reports::require(
            count($targets) === 1 && $deaths !== [] && $targets[0]['nativeTarget'] == $deaths[0]['nativeDied'],
            'Selected exact native role death was not observed',
        );
        $killOrder = $targets[0]['killOrder'] ?? null;
        Reports::require(
            is_array($killOrder) && array_all(
                ['supervisor', 'native'],
                static fn(string $role): bool => ($killOrder[$role]['oomScoreAdj'] ?? null) === KillOrderEvidence::EXECUTION_UNIT
                    && ($killOrder[$role]['nice'] ?? null) === 0,
            ),
            'Execution supervisor and native role did not run at the execution unit score',
        );
        return [
            'source' => 'execution-native-role-killed',
            'target' => $targets[0]['nativeTarget'],
            'killOrder' => $killOrder,
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
        [$control, $execution] = Reports::json($root . '/containers-before.json');
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
        $units = KillOrderEvidence::unlimitedPair($control, $execution);
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
            'unitResources' => $units,
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
            $report['nativeLoss'] = self::nativeLost($root, true);
            $after = Reports::json($root . '/containers-after-pressure.json');
            Reports::require(!$after[0]['State']['OOMKilled'], 'Control OOM during native role loss');
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
                $trigger === 'automatic-native-loss',
                'Native role loss must cause automatic joint drain',
            );
            Reports::require(
                array_all($final, static fn(array $unit): bool => !$unit['State']['Running']),
                'Automatic pair drain missing',
            );
            $report['ownedFailures'] = [...$terminal, ...$uncertain];
            $report['drainTrigger'] = $trigger;
            $report['controlSurvivedNativeLoss'] = !$after[0]['State']['OOMKilled'];
        }
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
        Reports::require(count($media['videoJobs']) === $media['slots']['video'] && $media['audioBytes'] > 0, 'Missing admitted media');
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
                )) >= $media['slots']['video'],
                'Every video native slot did not stay occupied',
            );
            Reports::require(
                count(array_filter(
                    $processes,
                    static fn(array $process): bool => in_array(
                        '/media/source/track.flac',
                        $process['argv'],
                        true,
                    ),
                )) >= $media['slots']['audio'],
                'Every audio native slot did not stay occupied',
            );
        }
        $report['occupiedSlotSamples'] = count($occupied);
        $report['killOrder'] = [
            'control' => KillOrderEvidence::processes(Reports::window($preDrain, $start, $end), 'control'),
            'execution' => KillOrderEvidence::processes($occupied, 'execution'),
        ];
        $report['cpuPressure'] = KillOrderEvidence::pressureProcesses(Reports::samples($root . '/pressure.log'));
        $report['audioPressureMethod'] = 'Admitted FFmpeg process held with SIGSTOP; resumed after pressure';
        $usage = Reports::counters($execution[array_key_last($execution)]['cpu.stat'])['usage_usec'] - Reports::counters($execution[0]['cpu.stat'])['usage_usec'];
        $report['executionCpuSeconds'] = $usage / 1e6;
        Reports::require($usage >= 100_000_000, 'Execution did not sustain media CPU load');
        return $report;
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        if (count($arguments) !== 3 || !in_array($arguments[0], ['native-lost', 'verify'], true) || !in_array($arguments[2], ControlCapacity::PHASES, true)) {
            throw new InvalidArgumentException('Usage: control-capacity-verify.php native-lost|verify DIRECTORY ' . implode('|', ControlCapacity::PHASES));
        }
        if ($arguments[0] === 'native-lost') {
            self::nativeLost($arguments[1]);
        } else {
            $report = self::verify($arguments[1], $arguments[2]);
            Reports::publish($arguments[1] . '/qualification.json', $report);
            echo json_encode($report, JSON_THROW_ON_ERROR), "\n";
        }
        return 0;
    }
}
