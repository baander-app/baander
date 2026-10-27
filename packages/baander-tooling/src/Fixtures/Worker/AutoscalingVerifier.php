<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use InvalidArgumentException;

final class AutoscalingVerifier
{
    public const array THRESHOLDS = [
        'cpuMessages' => 16,
        'cpuDeadlineSeconds' => 70,
        'minimumHandlerCpuSeconds' => 16,
        'changeCooldownSeconds' => 5,
        'schedulerDeadlineSeconds' => 10,
        'messagesPerBurst' => 48,
        'handlerSeconds' => 2,
        'burstDeadlineSeconds' => 150,
        'idleDeadlineSeconds' => 110,
        'callbackDeadlineSeconds' => 480,
        'profileDeadlineSeconds' => 600,
        'maximumSampleGapSeconds' => 5,
        'mediaControlDeadlineSeconds' => 3,
        'peerMaximumAgeSeconds' => 2,
        'publicationMaximumAgeSeconds' => 3,
        'pressureBoundaryGapSeconds' => 2,
        'pressureMinimumSeconds' => 60,
        'minimumSpeedups' => ['2' => 1.15, '4' => 1.4],
    ];

    /** @return list<array<string,mixed>> */
    public static function samples(string $root, string $unit = 'control'): array
    {
        return Reports::samples($root . '/' . $unit . '-samples.jsonl');
    }

    /** @param list<array<string,mixed>> $values
     * @return array<int,array<string,mixed>>
     */
    public static function receipts(array $values, string $batch): array
    {
        $receipts = [];
        foreach ($values as $sample) {
            foreach (explode("\n", trim($sample['completions'] ?? '')) as $line) {
                if ($line === '') {
                    continue;
                }
                $receipt = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($receipt['batch'] === $batch) {
                    $key = $receipt['sequence'];
                    Reports::require(
                        !isset($receipts[$key]) || $receipts[$key] == $receipt,
                        'Duplicate workload completion',
                    );
                    $receipts[$key] = $receipt;
                }
            }
        }
        return $receipts;
    }

    /** @return array<string,mixed> */
    public static function burst(string $root, string $batch): array
    {
        $publication = Reports::json($root . '/' . $batch . '.json');
        $completed = self::receipts(self::samples($root), $batch);
        $expected = $batch === 'cpu' ? self::THRESHOLDS['cpuMessages'] : self::THRESHOLDS['messagesPerBurst'];
        $kind = $batch === 'cpu' ? 'cpu' : 'io';
        Reports::require(
            $publication['count'] === $expected && $publication['kind'] === $kind,
            'Workload publication differs from declared fixture',
        );
        $keys = array_keys($completed);
        sort($keys, SORT_NUMERIC);
        Reports::require(
            $keys === range(0, $expected - 1),
            $batch . ': ' . count($completed) . '/' . $expected . ' complete',
        );
        $ended = max(array_column($completed, 'finished'));
        $elapsed = $ended - $publication['started'];
        Reports::require(
            $elapsed > 0 && $elapsed <= ($kind === 'cpu' ? self::THRESHOLDS['cpuDeadlineSeconds'] : self::THRESHOLDS['burstDeadlineSeconds']),
            'Burst exceeded deadline',
        );
        Reports::require(
            array_all(
                $completed,
                static fn(array $receipt): bool => $receipt['finished'] - $receipt['started'] >= 1.9,
            ),
            'I/O wait missing',
        );
        Reports::require(
            array_all($completed, static fn(array $receipt): bool => $receipt['kind'] === $kind),
            'Wrong handler workload kind',
        );
        $cpuSeconds = array_column($completed, 'cpuSeconds')
            |> array_sum(...);
        if ($kind === 'cpu') {
            Reports::require(
                $cpuSeconds >= self::THRESHOLDS['minimumHandlerCpuSeconds'],
                'Ordinary handlers did not perform the declared CPU work',
            );
        }
        $pids = array_column($completed, 'pid')
            |> array_unique(...)
            |> array_values(...);
        sort($pids, SORT_NUMERIC);
        return [
            'batch' => $batch,
            'seconds' => $elapsed,
            'throughput' => $expected / $elapsed,
            'handlerCpuSeconds' => $cpuSeconds,
            'pids' => $pids,
            'ended' => $ended,
        ];
    }

    public static function idle(string $root): void
    {
        $values = self::samples($root);
        Reports::require(count($values) >= 3, 'Missing idle samples');
        foreach (array_slice($values, -3) as $sample) {
            $state = $sample['admission'];
            Reports::require($state['target'] === 1 && !$state['fatal'], 'Invalid idle admission');
            Reports::require(
                array_all(
                    $state['slots'],
                    static fn(array $slot): bool => !in_array(
                        $slot['state'],
                        [
                            'busy',
                            'completing',
                        ],
                        true,
                    ),
                ),
                'Busy slot remained at idle checkpoint',
            );
            Reports::require(
                array_all(
                    $state['slots'],
                    static fn(array $slot): bool => $slot['requestId'] === null || $slot['state'] === 'receiving',
                ),
                'Owned delivery remained at idle checkpoint',
            );
            $age = $sample['monotonic'] - $state['controllerAt'];
            Reports::require(
                $age >= -.25 && $age < self::THRESHOLDS['publicationMaximumAgeSeconds'],
                'Stale idle controller publication',
            );
            $evidence = $state['evidence'];
            Reports::require(
                ($evidence['queueState'] ?? null) === 'known',
                'Idle checkpoint requires known queue evidence',
            );
            $queueAge = $evidence['queueSampleAgeSeconds'] ?? null;
            Reports::require(
                (is_int($queueAge) || is_float($queueAge)) && is_finite($queueAge) && $queueAge >= 0,
                'Invalid idle queue age',
            );
            Reports::require(
                max(0, $age) + $queueAge < self::THRESHOLDS['publicationMaximumAgeSeconds'],
                'Idle checkpoint queue evidence was stale',
            );
            Reports::require(
                array_all(
                    ['unreadCount', 'pendingCount', 'dueCount'],
                    static fn(string $key): bool => ($evidence[$key] ?? null) === 0,
                ),
                'Queue work remained at idle checkpoint',
            );
        }
    }

    /** @param list<array<string,mixed>> $values
     * @return list<array<string,mixed>>
     */
    public static function streamWindow(array $values, float $start, float $end, string $name): array
    {
        Reports::require(count($values) > 20, 'Insufficient ' . $name . ' samples');
        $gaps = [];
        foreach (array_slice($values, 1) as $index => $sample) {
            $gaps[] = $sample['monotonic'] - $values[$index]['monotonic'];
        }
        Reports::require(
            $gaps !== [] && min($gaps) > 0 && max($gaps) <= self::THRESHOLDS['maximumSampleGapSeconds'],
            $name . ' sampling stalled or reversed',
        );
        $window = Reports::window($values, $start, $end);
        Reports::require(count($window) > 1, 'Missing ' . $name . ' pressure window');
        Reports::require(
            $window[0]['monotonic'] - $start <= 2 && $end - $window[array_key_last($window)]['monotonic'] <= 2,
            'Truncated ' . $name . ' pressure window',
        );
        return $window;
    }

    /** @param array<string,mixed> $sample
     * @param array<string,mixed> $identity
     */
    public static function currentAuthority(array $sample, array $identity): string
    {
        $now = $sample['monotonic'];
        $state = $sample['admission'];
        Reports::require(Reports::identity($state, $identity), 'Foreign ledger authority');
        Reports::require(
            $now - $state['controllerAt'] >= -.25 && $now - $state['controllerAt'] < 3,
            'Stale controller publication',
        );
        foreach (['control', 'execution'] as $unit) {
            $peer = $sample[$unit];
            Reports::require($peer !== [] && Reports::identity($peer, $identity), 'Foreign peer authority');
            Reports::require(
                $peer['unit'] === $unit && $peer['ready'] === true && $peer['draining'] === false,
                'Peer unavailable',
            );
            Reports::require(
                $now - $peer['time'] >= -.25 && $now - $peer['time'] < 2,
                'Peer heartbeat exceeded continuity deadline',
            );
        }
        $resource = $sample['resource'];
        Reports::require(
            $resource !== [] && Reports::identity($resource, $identity),
            'Foreign resource authority',
        );
        $observation = $resource['observation'];
        Reports::require(
            $now - $observation['sampledAt'] >= -.25 && $now - $observation['sampledAt'] < 3,
            'Stale resource publication',
        );
        Reports::require(
            in_array($observation['state'], ['known', 'unknown', 'unavailable'], true),
            'Invalid resource state',
        );
        if ($observation['state'] === 'known') {
            Reports::require(
                array_all(
                    ['cpuUtilization', 'memoryRatio', 'pidsRatio'],
                    static fn(string $key): bool => (is_int($observation[$key] ?? null) || is_float($observation[$key] ?? null))
                        && is_finite($observation[$key])
                        && $observation[$key] >= 0,
                ),
                'Known resource publication lacks pressure measurements',
            );
        }
        $status = $sample['status'];
        Reports::require(
            $status !== [] && $status['state'] === 'known' && $status['authorityValid'] === true && $status['controllerFresh'] === true,
            'Unavailable status authority/controller',
        );
        Reports::require(
            $status['controllerAgeSeconds'] >= 0 && $status['controllerAgeSeconds'] < 3,
            'Status controller age exceeded deadline',
        );
        Reports::require(
            $status['resourceFresh'] === ($status['resources']['state'] === 'known'),
            'Resource status freshness contradicts its state',
        );
        foreach (['queue', 'resource'] as $component) {
            if (($state['evidence'][$component . 'State'] ?? null) === 'known') {
                $age = $state['evidence'][$component . 'SampleAgeSeconds'];
                Reports::require($age >= 0 && $age < 3, 'Known decision measurement was stale');
            }
        }
        return $observation['state'];
    }

    /** @param array<string,mixed> $sample
     * @return list<array{array<string,mixed>,list<string>}>
     */
    public static function nativeProcesses(array $sample): array
    {
        $result = [];
        foreach ($sample['processes'] as $process) {
            $arguments = explode("\0", rtrim($process['cmdline'] ?? '', "\0"));
            if (array_any($arguments, static fn(string $argument): bool => basename($argument) === 'ffmpeg')) {
                Reports::require(
                    $process['pid'] > 0 && ctype_digit((string) $process['startTicks']),
                    'Missing native PID identity',
                );
                $result[] = [$process, $arguments];
            }
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $containers
     * @param list<array<string,mixed>> $values
     * @param list<array<string,mixed>> $execution
     * @return array<string,mixed>
     */
    public static function startupMetrics(array $containers, array $values, array $execution): array
    {
        $result = [];
        foreach (['control' => [$containers[0], $values], 'execution' => [$containers[1], $execution]] as $unit => [
            $container,
            $source,
        ]) {
            $first = $source[0];
            $started = (float) (new \DateTimeImmutable($container['State']['StartedAt']))->format('U.u');
            $created = (float) (new \DateTimeImmutable($container['Created']))->format('U.u');
            $result[$unit] = [
                'createdToStartedSeconds' => $started - $created,
                'startedToCheckpointSeconds' => $first['wallTime'] - $started,
                'firstCheckpointMemoryBytes' => Reports::integer($first['memory.current']),
                'firstCheckpointPids' => Reports::integer($first['pids.current']),
                'residentProcessCount' => count($first['processes']),
                'residentRssBytes' => array_column($first['processes'], 'rssBytes')
                    |> array_sum(...),
            ];
            Reports::require(
                $result[$unit]['startedToCheckpointSeconds'] >= 0,
                'First checkpoint predates startup',
            );
        }
        $result['databaseConnectionsAtFirstCheckpoint'] = $values[0]['connections'];
        $result['scope'] = 'Container startup through first qualification sample includes application readiness, '
            . 'harness checks and the observation-gap probe; dependency/media preparation is outside this interval. '
            . 'First checkpoint process/RSS counts include the qualification observer.';
        return $result;
    }

    /** @return array<string,mixed> */
    public static function verify(string $root, int $maximum): array
    {
        $values = self::samples($root);
        Reports::require(count($values) > 20, 'Insufficient control samples');
        $first = $values[0]['lease'];
        Reports::require(
            array_all(
                $values,
                static fn(array $sample): bool => $sample['lease']['owner_boot_id'] === $first['owner_boot_id']
                    && $sample['lease']['epoch'] === $first['epoch']
                    && $sample['lease']['state'] === 'active'
                    && Reports::truth($sample['lease']['unexpired']),
            ),
            'Lease continuity failed',
        );
        $identity = array_intersect_key($values[0]['admission'], array_flip(['namespace', 'bootId', 'epoch']));
        Reports::require(
            $first['owner_boot_id'] === $identity['bootId'] && $first['epoch'] === $identity['epoch'],
            'Lease and publication authority differ',
        );
        $resourceStates = ['known' => 0, 'unknown' => 0, 'unavailable' => 0];
        foreach ($values as $sample) {
            ++$resourceStates[self::currentAuthority($sample, $identity)];
            $state = $sample['admission'];
            Reports::require(
                $state !== [] && !$state['fatal'] && count($state['slots']) === $maximum,
                'Invalid resident slot capacity',
            );
            $occupied = count(array_filter(
                $state['slots'],
                static fn(array $slot): bool => $slot['requestId'] !== null,
            ));
            Reports::require(
                $state['target'] >= 0 && $state['target'] <= $maximum && $occupied <= $maximum,
                'Resident occupancy exceeded',
            );
            Reports::require(
                !empty($sample['control']) && !empty($sample['execution']) && !empty($sample['resource']) && !empty($sample['status']),
                'Peer/resource/status evidence missing',
            );
        }
        $bursts = [];
        foreach (['cold', 'repeat'] as $name) {
            $result = self::burst($root, $name);
            $start = Reports::json($root . '/' . $name . '.json')['started'];
            $phase = Reports::window($values, $start, $result['ended']);
            Reports::require(
                $phase !== [] && max(array_column(array_column($phase, 'admission'), 'target')) === $maximum,
                'I/O burst did not reach admitted maximum',
            );
            Reports::require(
                array_any(
                    $phase,
                    static fn(array $sample): bool => ($sample['admission']['evidence']['durationSeconds'] ?? null) !== null,
                ),
                'Duration evidence missing',
            );
            $bursts[] = $result;
        }
        $media = Reports::json($root . '/media.json')['evidence'];
        Reports::require(
            count(array_unique($media['videoJobs'])) === 3 && $media['audioBytes'] >= 8192,
            'Missing admitted media work',
        );
        Reports::require(
            $media['desiredStateSamples'] !== [] && max(array_column(
                $media['desiredStateSamples'],
                'seconds',
            )) <= 3,
            'Media control exceeded deadline',
        );
        $cpuBurst = self::burst($root, 'cpu');
        $pressureStart = $media['pressureStartedAt'];
        $pressureEnd = $media['pressureEndedAt'];
        Reports::require($pressureEnd - $pressureStart >= 60, 'Pressure window lasted less than sixty seconds');
        self::streamWindow($values, $pressureStart, $pressureEnd, 'control');
        $pressure = Reports::window($values, $pressureStart + 5, $pressureEnd);
        Reports::require(
            $pressure !== [] && array_any(
                $pressure,
                static fn(array $sample): bool => in_array(
                    $sample['admission']['reason'],
                    [
                        'resource_growth_blocked',
                        'resource_pressure',
                        'severe_resource_pressure',
                    ],
                    true,
                ),
            ),
            'CPU pressure did not block growth',
        );
        $high = static fn(array $sample): bool => ($sample['admission']['evidence']['cpuUtilization'] ?? 0) >= .85
            || ($sample['admission']['evidence']['cpuThrottledRatio'] ?? 0) >= .1;
        Reports::require(array_any($pressure, $high), 'No measured CPU pressure');
        foreach (array_slice($pressure, 1) as $index => $after) {
            Reports::require(
                !$high($after) || $after['admission']['target'] <= $pressure[$index]['admission']['target'],
                'Admission grew during measured CPU pressure',
            );
        }
        $transitions = [];
        foreach (array_slice($values, 1) as $index => $after) {
            $before = $values[$index];
            Reports::require(
                $after['monotonic'] - $before['monotonic'] <= self::THRESHOLDS['maximumSampleGapSeconds'],
                'Control sampling stalled',
            );
            if ($before['admission']['target'] !== $after['admission']['target']) {
                $transitions[] = [
                    'monotonic' => $after['monotonic'],
                    'controllerAt' => $after['admission']['controllerAt'],
                    'before' => $before['admission']['target'],
                    'after' => $after['admission']['target'],
                    'reason' => $after['admission']['reason'],
                ];
            }
        }
        $normal = array_filter(
            $transitions,
            static fn(array $change): bool => $change['before'] > 0 && $change['after'] > 0 && !in_array(
                $change['reason'],
                [
                    'measurements_unavailable',
                    'authority_unavailable',
                    'severe_resource_pressure',
                ],
                true,
            ),
        )
            |> array_values(...);
        foreach (array_slice($normal, 1) as $index => $after) {
            Reports::require(
                $after['controllerAt'] - $normal[$index]['controllerAt'] >= self::THRESHOLDS['changeCooldownSeconds'] - .5,
                'Target changes violated cooldown',
            );
        }
        self::idle($root);
        $execution = self::samples($root, 'execution');
        Reports::require(
            $execution !== [] && array_all(
                $execution,
                static fn(array $sample): bool => !empty($sample['memory.current'])
                    && !empty($sample['pids.current'])
                    && !empty($sample['cpu.stat'])
                    && !empty($sample['processes']),
            ),
            'Missing execution measurements',
        );
        $executionWindow = self::streamWindow($execution, $pressureStart, $pressureEnd, 'execution');
        foreach ($execution as $sample) {
            self::currentAuthority($sample, $identity);
        }
        $cpuStart = Reports::json($root . '/cpu.json')['started'];
        $cpuControl = Reports::window($values, $cpuStart, $cpuBurst['ended']);
        $cpuExecution = Reports::window($execution, $cpuStart, $cpuBurst['ended']);
        Reports::require(count($cpuControl) > 2 && count($cpuExecution) > 2, 'Missing ordinary CPU observations');
        $cpuUsage = (
            Reports::counters($cpuExecution[array_key_last($cpuExecution)]['cpu.stat'])['usage_usec']
            - Reports::counters($cpuExecution[0]['cpu.stat'])['usage_usec']
        ) / 1e6;
        Reports::require($cpuUsage >= 16, 'Execution cgroup did not account ordinary CPU pressure');
        $cpuPressure = array_filter(
            $cpuControl,
            static fn(array $sample): bool => ($sample['admission']['evidence']['cpuUtilization'] ?? null) !== null,
        )
            |> array_values(...);
        $utilizations = array_column($cpuPressure, 'admission')
            |> (static fn(array $states): array => array_column($states, 'evidence'))
            |> (static fn(array $evidence): array => array_column($evidence, 'cpuUtilization'));
        Reports::require(
            $cpuPressure !== [] && max($utilizations) > 0,
            'Ordinary CPU utilization evidence missing',
        );
        $cpuTargets = array_column($cpuControl, 'admission')
            |> (static fn(array $states): array => array_column($states, 'target'));
        foreach (array_slice($cpuControl, 1) as $index => $after) {
            Reports::require(
                !$high($after) || $after['admission']['target'] <= $cpuControl[$index]['admission']['target'],
                'Ordinary admission grew on high CPU decision evidence',
            );
        }
        $cpuBurst += [
            'executionCpuSeconds' => $cpuUsage,
            'peakUtilization' => max($utilizations),
            'minimumTarget' => min($cpuTargets),
            'maximumTarget' => max($cpuTargets),
            'transitions' => Reports::window($transitions, $cpuStart, $cpuBurst['ended']),
        ];
        $backlog = $media['ordinaryBacklog'];
        Reports::require(
            count($backlog) > 1 && min(array_column($backlog, 'receiverDepth')) > 0,
            'Ordinary media backlog disappeared',
        );
        $backlogEnd = $backlog[array_key_last($backlog)];
        Reports::require(
            $backlogEnd['acknowledged'] > $backlog[0]['acknowledged'],
            'Ordinary consumer made no acknowledged media progress',
        );
        Reports::require(
            $backlogEnd['relayedEvents'] > $backlog[0]['relayedEvents'],
            'Outbox relay made no media progress',
        );
        for ($offset = 0; $offset < 50; $offset += 10) {
            $window = array_filter(
                $backlog,
                static fn(array $row): bool => $pressureStart + $offset <= $row['monotonic'] && $row['monotonic'] < $pressureStart + $offset + 10,
            )
                |> array_values(...);
            Reports::require(
                count($window) > 1 && $window[array_key_last($window)]['acknowledged'] > $window[0]['acknowledged'],
                'Ordinary consumer stalled for ten seconds under media pressure',
            );
        }
        $occurrences = $media['dueOccurrences'];
        Reports::require(count($occurrences) >= 4, 'Missing due scheduler pressure probes');
        $completionTimes = [];
        foreach ($occurrences as $occurrence) {
            $returned = $occurrence['execution'];
            Reports::require(
                !empty($returned) && Reports::truth($returned['returned']),
                'Due scheduler occurrence did not return',
            );
            Reports::require(
                $returned['deployment_namespace'] === $identity['namespace']
                    && $returned['deployment_boot_id'] === $identity['bootId']
                    && Reports::integer($returned['deployment_epoch']) === $identity['epoch'],
                'Scheduler occurrence used foreign deployment authority',
            );
            $duration = Reports::number($returned['completion_seconds']);
            Reports::require(
                $duration >= 0 && $duration <= self::THRESHOLDS['schedulerDeadlineSeconds'],
                'Due scheduler occurrence exceeded completion deadline',
            );
            $completionTimes[] = $duration;
        }
        $nativeCounts = [];
        $audioIdentities = null;
        foreach ($executionWindow as $sample) {
            $native = self::nativeProcesses($sample);
            $videos = array_filter(
                $native,
                static fn(array $entry): bool => in_array(
                    '/media/source/video.mkv',
                    $entry[1],
                    true,
                ),
            );
            $audio = array_filter(
                $native,
                static fn(array $entry): bool => in_array(
                    '/media/source/track.flac',
                    $entry[1],
                    true,
                ),
            );
            Reports::require(
                count($videos) >= 3 && count($audio) >= 1,
                'Three video and one audio native slots did not stay occupied',
            );
            $identities = [];
            foreach ($audio as [$process]) {
                $identities[$process['pid'] . ':' . $process['startTicks']] = [
                    $process['pid'],
                    (string) $process['startTicks'],
                ];
            }
            $audioIdentities = $audioIdentities === null ? $identities : array_intersect_key(
                $audioIdentities,
                $identities,
            );
            $nativeCounts[] = count($native);
        }
        Reports::require(!empty($audioIdentities), 'Admitted held audio PID identity changed during pressure');
        $heldAudio = array_values($audioIdentities);
        sort($heldAudio);
        foreach ([$values, $execution] as $source) {
            Reports::require(
                $source[0]['monotonic'] <= $values[0]['monotonic'] + 2 && $source[array_key_last($source)]['monotonic'] >= $values[array_key_last($values)]['monotonic'] - 2,
                'Truncated complete observation interval',
            );
        }
        $heartbeat = Reports::timings(array_map(
            static fn(array $sample): float => $sample['monotonic'] - $sample['control']['time'],
            $values,
        ));
        $cpuSeconds = (Reports::counters($execution[array_key_last($execution)]['cpu.stat'])['usage_usec'] - Reports::counters($execution[0]['cpu.stat'])['usage_usec']) / 1e6;
        $result = [
            'maximum' => $maximum,
            'bursts' => $bursts,
            'ordinaryCpu' => $cpuBurst,
            'mediaOrdinaryProgress' => [
                'minimumReceiverDepth' => min(array_column($backlog, 'receiverDepth')),
                'acknowledged' => $backlogEnd['acknowledged'] - $backlog[0]['acknowledged'],
                'relayedEvents' => $backlogEnd['relayedEvents'] - $backlog[0]['relayedEvents'],
            ],
            'mediaScheduler' => [
                'returned' => count($occurrences),
                'completion' => Reports::timings($completionTimes),
            ],
            'samples' => count($values),
            'transitions' => $transitions,
            'peakMemoryBytes' => max(array_map(
                static fn(array $sample): int => Reports::integer($sample['memory.current']),
                $execution,
            )),
            'peakPids' => max(array_map(
                static fn(array $sample): int => Reports::integer($sample['pids.current']),
                $execution,
            )),
            'peakDatabaseConnections' => max(array_column($values, 'connections')),
            'thresholds' => self::THRESHOLDS,
            'heartbeat' => $heartbeat,
            'mediaControl' => Reports::timings(array_column($media['desiredStateSamples'], 'seconds')),
            'resourceStates' => $resourceStates,
            'observationGap' => self::gapReceipt($root),
            'startup' => self::startupMetrics(
                Reports::json($root . '/containers-before.json'),
                $values,
                $execution,
            ),
            'executionCpuSeconds' => $cpuSeconds,
            'nativePressure' => [
                'samples' => count($executionWindow),
                'minimumProcesses' => min($nativeCounts),
                'heldAudioIdentities' => $heldAudio,
            ],
            'peakResidentRssBytes' => max(array_map(
                static fn(array $sample): int|float => array_column(
                    $sample['processes'],
                    'rssBytes',
                )
                    |> array_sum(...),
                $execution,
            )),
            'coverageLimits' => [
                'The cold artifact labels the first 48-message burst after harness work and the observation-gap probe. '
                    . 'Duration EWMA is already initialized; pristine cold-start performance is not measured. '
                    . 'Pure policy tests cover absent duration history.',
                'Blocking wait models I/O, not an external provider latency distribution.',
                'Retry race coverage comes from the separate real Messenger integration suite.',
            ],
        ];
        Reports::publish($root . '/qualification.json', $result);
        return $result;
    }

    /** @return array<string,mixed> */
    public static function gapReceipt(string $root): array
    {
        $receipt = Reports::json($root . '/gap.json');
        $identity = array_intersect_key($receipt, array_flip(['namespace', 'bootId', 'epoch']));
        $controller = $receipt['controller'];
        Reports::require(
            $controller['pid'] > 0 && ctype_digit((string) $controller['startTicks']),
            'Missing controller PID identity',
        );
        Reports::require(
            $controller['role'] === 'autoscaler' && $controller['generation'] === 1,
            'Unexpected controller role',
        );
        Reports::require(
            $receipt['finishedAt'] - $receipt['startedAt'] > 0 && $receipt['finishedAt'] - $receipt['startedAt'] <= 25,
            'Observation gap exceeded bounded deadline',
        );
        Reports::require(
            $receipt['publishedAt'] - $receipt['stoppedAt'] >= $receipt['maxSampleAgeSeconds'] + .19,
            'Message published before controller became stale',
        );
        Reports::require(
            $receipt['staleAt'] - $receipt['publishedAt'] >= 1.19,
            'Insufficient unread hold observation',
        );
        Reports::require(
            $receipt['staleAt'] <= $receipt['resumedAt'] && $receipt['resumedAt'] < $receipt['recoveredAt'],
            'Invalid pause/recovery chronology',
        );
        Reports::require(
            $receipt['recoveredAt'] - $receipt['resumedAt'] <= 12,
            'Observation recovery exceeded twelve seconds',
        );
        $stale = $receipt['staleStatus'];
        Reports::require(
            $stale['state'] === 'unavailable' && $stale['controllerFresh'] === false && $stale['authorityValid'] === true,
            'Invalid stale controller status',
        );
        Reports::require(
            $stale['controllerAgeSeconds'] >= $receipt['maxSampleAgeSeconds'],
            'Unavailability did not represent controller staleness',
        );
        $recovered = $receipt['recoveredStatus'];
        Reports::require(
            $recovered['state'] === 'known' && $recovered['controllerFresh'] === true && $recovered['authorityValid'] === true,
            'Missing recovered controller status',
        );
        Reports::require(
            $recovered['controllerAgeSeconds'] >= 0 && $recovered['controllerAgeSeconds'] < $receipt['maxSampleAgeSeconds'],
            'Recovered controller remains stale',
        );
        $proof = $receipt['unreadProof'];
        Reports::require(
            $proof['present'] === 1 && $proof['pending'] === 0 && $proof['unread'] === 1,
            'Work consumed during stale controller admission',
        );
        $redisId = static fn(string $value): array => array_map(intval(...), explode('-', $value));
        Reports::require(
            $redisId($proof['lastDeliveredId']) < $redisId($receipt['messageId']),
            'Stale controller advanced Redis delivery identity',
        );
        foreach (['staleHealth', 'recoveredHealth'] as $name) {
            $health = $receipt[$name];
            $authority = $health['lease'];
            Reports::require(
                $authority['owner_boot_id'] === $identity['bootId'] && Reports::integer($authority['epoch']) === $identity['epoch'],
                'Foreign gap lease authority',
            );
            Reports::require(
                $authority['state'] === 'active' && Reports::truth($authority['unexpired']),
                'Gap lease inactive',
            );
            foreach (['control', 'execution'] as $unit) {
                $peer = $health['peers'][$unit];
                Reports::require(Reports::identity($peer, $identity), 'Foreign gap peer authority');
                Reports::require(
                    $peer['unit'] === $unit && $peer['ready'] === true && $peer['draining'] === false,
                    'Gap peer unavailable',
                );
                Reports::require(
                    $health['sampledAt'] - $peer['time'] >= -.25 && $health['sampledAt'] - $peer['time'] < 2,
                    'Observation gap disturbed peer freshness',
                );
            }
        }
        $completion = $receipt['completion'];
        Reports::require(
            $completion['batch'] === 'observation-gap' && $completion['sequence'] === 0,
            'Unexpected observation-gap completion',
        );
        Reports::require(
            $receipt['resumedAt'] <= $completion['started'] && $completion['started'] < $completion['finished'] && $completion['finished'] <= $receipt['recoveredAt'] + .25,
            'Gap completion preceded recovery',
        );
        Reports::require($completion['finished'] - $completion['started'] >= 1.9, 'Gap workload wait missing');
        return [
            'seconds' => $receipt['finishedAt'] - $receipt['startedAt'],
            'staleControllerAgeSeconds' => $stale['controllerAgeSeconds'],
            'recoverySeconds' => $receipt['recoveredAt'] - $receipt['resumedAt'],
            'controller' => $controller,
            'messageId' => $receipt['messageId'],
            'unreadWhileStale' => true,
            'completedAfterRecovery' => true,
        ];
    }

    public static function compare(string $root): void
    {
        $results = [];
        foreach ([1, 2, 4] as $maximum) {
            $results[$maximum] = Reports::json($root . '/' . $maximum . '/qualification.json');
        }
        $speedups = [];
        foreach ([2, 4] as $maximum) {
            $speedups[$maximum] = [];
            foreach ($results[1]['bursts'] as $index => $base) {
                if (isset($results[$maximum]['bursts'][$index])) {
                    $speedups[$maximum][] = $base['seconds'] / $results[$maximum]['bursts'][$index]['seconds'];
                }
            }
            Reports::require(
                $speedups[$maximum] !== [] && min($speedups[$maximum]) >= self::THRESHOLDS['minimumSpeedups'][$maximum],
                'Insufficient measured speedup',
            );
        }
        Reports::publish($root . '/comparison.json', ['speedups' => $speedups, 'thresholds' => self::THRESHOLDS]);
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        $mode = $arguments[0] ?? throw new InvalidArgumentException('Supply autoscaling verification mode');
        if ($mode === 'thresholds') {
            echo json_encode(self::THRESHOLDS, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
            return 0;
        }
        $root = $arguments[1] ?? throw new InvalidArgumentException('Supply evidence directory');
        $result = match ($mode) {
            'burst' => self::burst($root, $arguments[2] ?? throw new InvalidArgumentException('Supply batch')),
            'idle' => self::idle($root),
            'verify' => self::verify(
                $root,
                Reports::integer($arguments[2] ?? throw new InvalidArgumentException('Supply maximum')),
            ),
            'gap' => self::gapReceipt($root),
            'compare' => self::compare($root),
            default => throw new InvalidArgumentException('Unknown autoscaling mode: ' . $mode),
        };
        if ($mode === 'verify') {
            echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
        }
        return 0;
    }
}
