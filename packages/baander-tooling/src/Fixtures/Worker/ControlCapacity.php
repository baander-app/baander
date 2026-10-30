<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Runtime;
use Throwable;

final class ControlCapacity
{
    /** @return array<string,array{validBaselineSamples:int,firstMonotonic:int|float,lastMonotonic:int|float}> */
    public static function baseline(string $artifacts): array
    {
        $receipt = [];
        foreach (['control', 'execution'] as $unit) {
            $valid = [];
            foreach (Reports::samples($artifacts . '/' . $unit . '-samples.jsonl', EvidenceMode::Live) as $sample) {
                if (!is_int($sample['monotonic'] ?? null) && !is_float($sample['monotonic'] ?? null)) {
                    continue;
                }
                $counters = true;
                foreach (['cpu.stat', 'memory.events', 'pids.events'] as $key) {
                    if (!is_string($sample[$key] ?? null)) {
                        $counters = false;
                    }
                }
                if (!$counters || ($unit === 'control' && (!is_array($sample['heartbeat'] ?? null) || array_is_list($sample['heartbeat']) || !is_array($sample['lease'] ?? null) || array_is_list($sample['lease'])))) {
                    continue;
                }
                $valid[] = $sample['monotonic'];
            }
            Runtime::require(count($valid) > 10, 'Capacity sampler requires eleven valid baseline samples');
            $receipt[$unit] = [
                'validBaselineSamples' => count($valid),
                'firstMonotonic' => $valid[0],
                'lastMonotonic' => $valid[array_key_last($valid)],
            ];
        }
        return $receipt;
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        if ($arguments === []) {
            $runtime = new Runtime($root, budget: 'workerCapacity');
            $artifacts = Runtime::artifacts($root, 'control-capacity');
            try {
                foreach (['cpu', 'memory', 'pid'] as $phase) {
                    Checkpoint::operator(
                        $runtime,
                        $artifacts . '/' . $phase,
                        [
                            'BAANDER_TEST_CAPACITY_PHASE' => $phase,
                            'BAANDER_TEST_TWO_COMMAND' => '1',
                            'BAANDER_TEST_CONTROL_CAPACITY_CHECKPOINT' => $root . '/scripts/test-worker-control-capacity-container.sh',
                        ],
                    );
                }
                echo 'Control capacity evidence: ', $artifacts, "\n";
                return 0;
            } finally {
                $runtime->close();
            }
        }
        $checkpoint = new Checkpoint($arguments, $root, 240);
        $runtime = $checkpoint->runtime;
        $docker = $checkpoint->docker;
        $phase = $runtime->environment['BAANDER_TEST_CAPACITY_PHASE'] ?? '';
        Runtime::require(
            in_array($phase, ['cpu', 'memory', 'pid'], true),
            'Set BAANDER_TEST_CAPACITY_PHASE to cpu, memory or pid',
        );
        try {
            $checkpoint->capture(['info', '--format', '{{json .}}'], 'daemon.json');
            $checkpoint->inspectBefore();
            $docker->run(
                [
                    'exec',
                    '-i',
                    $checkpoint->postgres,
                    'psql',
                    '-X',
                    '-w',
                    '-U',
                    'baander',
                    '-d',
                    $checkpoint->database,
                ],
                input: self::POSTGRES_DIAGNOSTICS,
                logPath: $checkpoint->artifacts . '/postgres-before.txt',
            );
            $control = $checkpoint->measurements->container(
                [
                    'exec',
                    $checkpoint->control,
                    'php',
                    'tests/Fixtures/Worker/control-capacity-sample.php',
                    '180',
                    'control',
                ],
                'control-samples.jsonl',
                'control-sampler.log',
                185,
            );
            $execution = $checkpoint->measurements->container(
                [
                    'exec',
                    $checkpoint->execution,
                    'php',
                    'tests/Fixtures/Worker/control-capacity-sample.php',
                    '180',
                    'execution',
                ],
                'execution-samples.jsonl',
                'execution-sampler.log',
                185,
            );
            $units = Runtime::json($checkpoint->artifacts . '/containers-before.json');
            $controlId = $units[0]['Id'];
            $executionId = $units[1]['Id'];
            $eventFilter = [
                '--filter',
                'type=container',
                '--filter',
                'container=' . $controlId,
                '--filter',
                'container=' . $executionId,
                '--filter',
                'event=oom',
                '--format',
                '{{json .}}',
            ];
            $subscriber = $checkpoint->background(
                ['events', ...$eventFilter],
                'worker-oom-events.jsonl',
                'worker-oom-events.log',
                180,
            );
            $since = (new \DateTimeImmutable(Runtime::json($checkpoint->artifacts . '/daemon.json')['SystemTime']))->getTimestamp() - 1;
            $postgres = $checkpoint->measurements->host(
                [PHP_BINARY, 'tests/Fixtures/Worker/control-capacity-postgres-sample.php',
                    $checkpoint->postgres, $checkpoint->web, '180', $checkpoint->database,
                    $checkpoint->artifacts . '/postgres-sampler-ready.json'],
                'postgres-samples.jsonl',
                'postgres-sampler.log',
                185,
            );
            $client = null;
            if ($phase === 'cpu') {
                $client = $checkpoint->background(
                    [
                        'exec',
                        $checkpoint->web,
                        'php',
                        'tests/Fixtures/TwoCommand/deployment.php',
                        'capacity',
                    ],
                    'media-client.log',
                    'media-client.log.stderr',
                    180,
                );
                $checkpoint->mediaReady($client);
                $checkpoint->capture(
                    ['top', $checkpoint->execution, '-eo', 'pid,ppid,args'],
                    'occupied-slots.txt',
                );
            }
            $runtime->await(
                static function () use ($runtime, $checkpoint, $control, $execution, $postgres): bool {
                    Runtime::require(
                        $runtime->alive($control) && $runtime->alive($execution) && $runtime->alive($postgres),
                        'Capacity sampler exited before baseline',
                    );
                    try {
                        $receipt = self::baseline($checkpoint->artifacts);
                        Runtime::require(
                            is_file($checkpoint->artifacts . '/postgres-sampler-ready.json'),
                            'PostgreSQL sampler has not proved its disposable target',
                        );
                        Runtime::writeJson($checkpoint->artifacts . '/samplers-ready.json', $receipt);
                        return true;
                    } catch (Throwable) {
                        return false;
                    }
                },
                10,
                .1,
                'Capacity samplers did not establish eleven valid baseline samples within ten seconds',
            );
            Runtime::require($runtime->alive($subscriber), 'Docker OOM subscriber exited');
            $exhaustionStarted = Runtime::now();
            $failure = null;
            if ($phase !== 'cpu') {
                $failure = $checkpoint->background(
                    [
                        'exec',
                        $checkpoint->control,
                        'php',
                        'tests/Fixtures/Worker/control-capacity-owned-failure.php',
                        $phase,
                    ],
                    'owned-failure.jsonl',
                    'owned-failure.log',
                    180,
                );
            }
            $pressure = $checkpoint->background(
                [
                    'exec',
                    $checkpoint->execution,
                    'php',
                    '-d',
                    'memory_limit=-1',
                    'tests/Fixtures/Worker/control-capacity-pressure.php',
                    $phase,
                ],
                'pressure.log',
                'pressure.log.stderr',
                180,
            );
            if ($phase === 'cpu') {
                $checkpoint->pressureReady($pressure);
                $docker->run(['exec', $checkpoint->web, 'touch', 'storage/two-command/capacity-pressure-start']);
                $runtime->wait($client);
                $checkpoint->copy(
                    $checkpoint->web . ':' . $runtime->layout()->path('storage/two-command/result-capacity.json'),
                    'media.json',
                );
                $checkpoint->inspectAfter('containers-after-pressure.json');
                $runtime->wait($pressure);
            } else {
                $runtime->await(
                    static function () use ($checkpoint, $phase): bool {
                        try {
                            ControlCapacityVerifier::exhausted($checkpoint->artifacts, $phase);
                            return true;
                        } catch (Throwable) {
                            return false;
                        }
                    },
                    15,
                    .1,
                    'Execution capacity was not exhausted',
                );
                ControlCapacityVerifier::exhausted($checkpoint->artifacts, $phase);
                $checkpoint->inspectAfter('containers-after-pressure.json');
            }
            if ($phase === 'cpu') {
                $checkpoint->measurements->finish();
            }
            $started = $phase === 'cpu' ? Runtime::now() : $exhaustionStarted;
            file_put_contents(
                $checkpoint->artifacts . '/drain-trigger.txt',
                ($phase === 'cpu' ? 'requested-graceful-shutdown-after-contained-pressure' : 'automatic-execution-exhaustion') . "\n",
            );
            if ($phase === 'cpu') {
                $docker->run(['kill', '--signal', 'TERM', $checkpoint->control], check: false);
            }
            $runtime->await(
                static function () use ($checkpoint): bool {
                    $checkpoint->inspectAfter('drain.json');
                    return array_all(
                        Runtime::json($checkpoint->artifacts . '/drain.json'),
                        static fn(array $unit): bool => !$unit['State']['Running'],
                    );
                },
                max(.1, 35 - (Runtime::now() - $started)),
                .1,
                'Pair exceeded 35-second drain',
            );
            file_put_contents(
                $checkpoint->artifacts . '/drain-seconds.txt',
                (string) (int) (Runtime::now() - $started) . "\n",
            );
            Runtime::require($runtime->alive($subscriber), 'Docker OOM subscriber exited before drain evidence');
            $until = (new \DateTimeImmutable($docker->run(['info', '--format', '{{.SystemTime}}'])->stdout
                |> trim(...)))->getTimestamp() + 1;
            $docker->run(
                [
                    'events',
                    '--since',
                    (string) $since,
                    '--until',
                    (string) $until,
                    ...$eventFilter,
                ],
                timeout: 10,
                logPath: $checkpoint->artifacts . '/worker-oom-events-final.jsonl',
                stderrLogPath: $checkpoint->artifacts . '/worker-oom-events-final.log',
            );
            Runtime::require($runtime->alive($subscriber), 'Docker OOM subscriber exited during final capture');
            $stopped = $runtime->stop($subscriber);
            Runtime::require($stopped->exitCode === 143, 'Docker OOM subscriber ended unexpectedly');
            Runtime::writeJson(
                $checkpoint->artifacts . '/worker-oom-events-capture.json',
                [
                    'containerIds' => ['control' => $controlId, 'execution' => $executionId],
                    'sinceUnixSeconds' => $since,
                    'untilUnixSeconds' => $until,
                    'sinceSource' => 'daemon-info-before-pressure',
                    'untilSource' => 'daemon-info-after-drain',
                    'captureExitCode' => 0,
                    'monitorAliveAfterDrainAndCapture' => true,
                    'monitorIntentionallyStopped' => true,
                    'monitorExitCode' => $stopped->exitCode,
                ],
            );
            if ($phase !== 'cpu') {
                $completion = new ContainedSamplerDrain($checkpoint->artifacts, $phase, [
                    'control' => ['name' => $checkpoint->control, 'id' => $controlId],
                    'execution' => ['name' => $checkpoint->execution, 'id' => $executionId],
                ]);
                $pressureResult = $runtime->wait($pressure, check: false);
                $failureResult = $runtime->wait($failure, check: false);
                $completion->checkOutcome($pressureResult);
                $completion->checkOutcome($failureResult);
                // The final atomic heartbeat survives PID 1; an in-container sampler may miss its brief drain window.
                $source = $completion->terminalHeartbeatSource();
                $checkpoint->copy($source, 'control-drain-heartbeat.json');
                Runtime::writeJson($checkpoint->artifacts . '/control-drain-capture.json', [
                    'containerId' => $controlId, 'source' => $source, 'copiedAfterStop' => true,
                ]);
                $checkpoint->measurements->finish($completion);
                // Joint drain can retire pressure PHP after it has flushed terminal evidence.
                $completion->completePressure($pressureResult);
                $completion->completeOwnedFailure($failureResult);
            }
            $runtime->close();
            return ControlCapacityVerifier::main(['verify', $checkpoint->artifacts, $phase]);
        } finally {
            $runtime->close();
        }
    }

    private const string POSTGRES_DIAGNOSTICS = <<<'SQL'
        \pset pager off
        SELECT name, setting, unit, source FROM pg_settings WHERE name IN
        ('shared_buffers', 'work_mem', 'hash_mem_multiplier', 'maintenance_work_mem', 'autovacuum_work_mem',
         'max_connections', 'max_worker_processes', 'max_parallel_workers', 'max_parallel_workers_per_gather',
         'huge_pages', 'temp_buffers', 'effective_cache_size', 'jit', 'log_destination', 'logging_collector', 'log_directory') ORDER BY name;
        SELECT pid, backend_type, state, wait_event_type, wait_event,
               clock_timestamp() - query_start AS query_age, left(query, 500) AS query
        FROM pg_stat_activity WHERE datname = current_database() ORDER BY pid;
        EXPLAIN (COSTS, VERBOSE) SELECT owner_boot_id, epoch, state, expires_at > clock_timestamp() AS unexpired
        FROM worker_deployment_leases WHERE namespace IS NOT NULL;
        SQL;
}
