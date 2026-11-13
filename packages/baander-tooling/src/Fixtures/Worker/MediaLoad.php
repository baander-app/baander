<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Deployment\Pooler;

/**
 * The media-control drill's load proof: a full deployment through the pooler, with no
 * container limits, runs every configured video slot at 1080p and every audio slot together
 * on real FFmpeg, after the playback governor has learned. The run records each stream's
 * progress against wall time, the governor's decisions, native launches and the daemon's
 * answer time, OOM events, containment, PostgreSQL backends and pooler queues, lease helper
 * lifetimes against their deadline, web latency and the kill order, then a single encode of
 * each codec on the idle host. MediaLoadVerifier judges the receipt.
 */
final class MediaLoad
{
    public const array PROFILES = [
        'software' => 'tests/Fixtures/Worker/deployment-profile-load-software.json',
        'hardware' => 'tests/Fixtures/Worker/deployment-profile-load-hardware.json',
    ];
    /** The supervisor rejects a lease helper reply it first observes at or after this many seconds. */
    public const float LEASE_DEADLINE_SECONDS = 1.5;
    /** A media child's native request fails once the daemon has not answered within this many seconds. */
    public const float NATIVE_RPC_DEADLINE_SECONDS = 1.0;
    private const string DIRECT_URL = 'postgresql://baander:test-only@postgres:5432/worker_operator_test?serverVersion=18&charset=utf8';
    /** Clients beyond the application pool that keep every server connection busy in short transactions. */
    private const int SATURATING_EXTRA_CLIENTS = 2;
    private const int SAMPLER_SECONDS = 1900;
    private const array SINGLE_ENCODERS = ['software' => 'libx264,libx265', 'hardware' => 'h264_vaapi,hevc_vaapi'];
    private const array HARDWARE_ENCODERS = ['_vaapi', '_qsv', '_nvenc', '_amf'];

    public static function profilePath(string $pass): string
    {
        return self::PROFILES[$pass] ?? throw new \InvalidArgumentException('The load proof runs a software or hardware pass', 2);
    }

    public static function run(Checkpoint $checkpoint, string $pass): int
    {
        self::profilePath($pass);
        $runtime = $checkpoint->runtime;
        $docker = $checkpoint->docker;
        $artifacts = $checkpoint->artifacts;
        $project = preg_replace('/-postgres$/D', '', $checkpoint->postgres);
        $storage = $runtime->layout()->path('storage/two-command');
        try {
            $checkpoint->inspectBefore();
            $checkpoint->capture(['inspect', $project . '-pooler', $project . '-redis', $checkpoint->postgres, $checkpoint->web], 'services-before.json');
            $oom = $docker->start(
                ['events', '--filter', 'event=oom', '--format', '{{json .}}'],
                timeout: self::SAMPLER_SECONDS + 100,
                logPath: $artifacts . '/oom-events.jsonl',
                stderrLogPath: $artifacts . '/oom-events.log',
            );
            foreach (['control' => $checkpoint->control, 'execution' => $checkpoint->execution] as $unit => $container) {
                $checkpoint->measurements->container(
                    ['exec', $container, 'php', 'tests/Fixtures/Worker/media-load-unit-sample.php', (string) self::SAMPLER_SECONDS, $unit],
                    $unit . '-samples.jsonl',
                    $unit . '-sampler.log',
                    self::SAMPLER_SECONDS + 50,
                );
            }
            $checkpoint->measurements->container(
                ['exec', $checkpoint->control, 'php', 'tests/Fixtures/Worker/media-load-native-probe.php', (string) self::SAMPLER_SECONDS],
                'native-probe.jsonl',
                'native-probe.log',
                self::SAMPLER_SECONDS + 50,
            );
            $checkpoint->measurements->container(
                ['exec', $checkpoint->postgres, 'env', 'PGPASSWORD=test-only', 'sh', '-ec', self::DATABASE_SAMPLER, 'sh', (string) self::SAMPLER_SECONDS],
                'database-samples.txt',
                'database-sampler.log',
                self::SAMPLER_SECONDS + 50,
            );
            $client = $checkpoint->background(['exec', '-e', 'BAANDER_TEST_DIRECT_DATABASE_URL=' . self::DIRECT_URL, $checkpoint->web,
                'php', '-d', 'memory_limit=2G', 'tests/Fixtures/TwoCommand/deployment.php', 'media-load'], 'media-load-client.log', 'media-load-client.log.stderr', self::SAMPLER_SECONDS);
            $web = $checkpoint->background(
                ['exec', $checkpoint->web, 'php', '-d', 'memory_limit=512M', 'tests/Fixtures/TwoCommand/deployment.php', 'media-load-web'],
                'media-load-web.log',
                'media-load-web.log.stderr',
                self::SAMPLER_SECONDS,
            );
            $runtime->await(function () use ($runtime, $client, $docker, $checkpoint): bool {
                \Baander\Tooling\Fixtures\Runtime::require($runtime->alive($client), 'Load client exited during the governor warm-up');
                return $docker->run(['exec', $checkpoint->web, 'test', '-f', 'storage/two-command/load-warm'], check: false)->exitCode === 0;
            }, 1200, 1, 'The governor warm-up did not finish');
            // Two clients more than the application pool has server connections hold short
            // transactions throughout the run, so the pool has no idle server and every other
            // pooled request, the web's included, queues for one.
            $saturator = $docker->start(
                ['exec', $checkpoint->postgres, 'env', 'PGPASSWORD=test-only', 'sh', '-ec', self::SATURATOR, 'sh',
                    (string) (Pooler::defaultPools()['application'] + self::SATURATING_EXTRA_CLIENTS), (string) self::SAMPLER_SECONDS],
                timeout: self::SAMPLER_SECONDS,
                logPath: $artifacts . '/saturator.log',
                stderrLogPath: $artifacts . '/saturator.log.stderr',
            );
            $runtime->pause(5);
            $docker->run(['exec', $checkpoint->web, 'touch', 'storage/two-command/load-go']);
            try {
                $runtime->wait($client);
            } catch (\Throwable $error) {
                // Keep the client's own evidence; the drill's cleanup removes the web container.
                foreach (['web-latency.jsonl', 'native-launches.jsonl'] as $name) {
                    $docker->run(['cp', $checkpoint->web . ':' . $storage . '/' . $name, $artifacts . '/failed-' . $name], check: false);
                }
                foreach (['control' => $checkpoint->control, 'execution' => $checkpoint->execution] as $unit => $container) {
                    $docker->run(['cp', $container . ':' . $runtime->layout()->path('var/log/prod.log'), $artifacts . '/failed-' . $unit . '-prod.log'], check: false);
                }
                throw $error;
            }
            $docker->run(['exec', $checkpoint->postgres, 'sh', '-c', 'kill -TERM "$(cat /tmp/load-saturator.pid)"'], check: false);
            $runtime->wait($saturator, check: false);
            $runtime->wait($web);
            // The run is over: one encode of each codec alone on the otherwise idle host.
            $docker->run(['exec', $checkpoint->web, 'rm', '-rf', 'storage/two-command/cost-progress']);
            $docker->run(['exec', $checkpoint->execution, 'sh', '-ec', 'mkdir -p "$1" && cp /media/source/load-1080p.mkv "$1/cost-1080p.mkv"', 'sh', $storage . '/single']);
            $checkpoint->capture(['exec', $checkpoint->execution, 'php', 'tests/Fixtures/Worker/media-cost-hardware.php', '/dev/dri/renderD128', '300',
                self::SINGLE_ENCODERS[$pass], '1', $storage . '/single'], 'single-samples.jsonl', 900);
            $checkpoint->measurements->finish();
            foreach (['result-media-load.json' => 'media-load-client.json', 'result-media-load-web.json' => 'media-load-web.json',
                'web-latency.jsonl' => 'web-latency.jsonl', 'native-launches.jsonl' => 'native-launches.jsonl'] as $source => $name) {
                $checkpoint->copy($checkpoint->web . ':' . $storage . '/' . $source, $name);
            }
            $checkpoint->copy($checkpoint->web . ':' . $runtime->layout()->path('storage/conversions/qol-state/governor_state.json'), 'governor-state.json');
            $checkpoint->capture(['exec', $checkpoint->execution, 'php', 'tests/Fixtures/Worker/MediaControlObservation.php', 'verify'], 'native-verification.json');
            foreach (['control' => $checkpoint->control, 'execution' => $checkpoint->execution] as $unit => $container) {
                // A missing log leaves the receipt without decisions, which the verifier rejects.
                $docker->run(['cp', $container . ':' . $runtime->layout()->path('var/log/prod.log'), $artifacts . '/' . $unit . '-prod.log'], check: false);
                $checkpoint->capture(['logs', $container], $unit . '-unit.log', 30);
            }
            $checkpoint->inspectAfter();
            $checkpoint->capture(['inspect', $project . '-pooler', $project . '-redis', $checkpoint->postgres, $checkpoint->web], 'services-after.json');
            $runtime->stop($oom);
            $receipt = self::receipt($artifacts, $pass);
            Reports::publish($artifacts . '/media-load.json', $receipt);
            $failures = MediaLoadVerifier::failures($receipt);
            Reports::publish($artifacts . '/media-load-verification.json', ['pass' => $pass, 'failures' => $failures]);
            echo json_encode(['pass' => $pass, 'summary' => $receipt['summary'], 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
            return $failures === [] ? 0 : 1;
        } finally {
            $runtime->close();
        }
    }

    /**
     * Builds the receipt from a run's artifacts.
     *
     * @return array<string,mixed>
     */
    public static function receipt(string $artifacts, string $pass): array
    {
        $client = Reports::json($artifacts . '/media-load-client.json')['evidence'];
        [$start, $end] = [Reports::number($client['runStartedAt']), Reports::number($client['runEndedAt'])];
        $wallOffset = Reports::number($client['clock']['wall']) - Reports::number($client['clock']['monotonic']);
        $runJobs = array_column($client['streams'], 'job');
        $logs = ['control' => self::logRecords($artifacts . '/control-prod.log'), 'execution' => self::logRecords($artifacts . '/execution-prod.log')];
        $units = [];
        foreach (['control', 'execution'] as $unit) {
            $units[$unit] = Reports::samples($artifacts . '/' . $unit . '-samples.jsonl');
        }
        $receipt = [
            'version' => 1,
            'pass' => $pass,
            'profile' => $client['profile'],
            'source' => $client['source'],
            'run' => ['seconds' => round($end - $start, 3)],
            'governor' => self::governor($client, $logs['control'], $runJobs),
            'video' => self::video($client, $pass),
            'audio' => self::audio($client),
            'occupancy' => self::occupancy($client),
            'nativeLaunches' => self::launches($artifacts, $runJobs),
            'native' => self::nativeProbe($artifacts, $start, $end),
            'failures' => self::failureRecords($logs, $artifacts),
            'oom' => self::oom($artifacts, $units),
            'containment' => self::containment($artifacts, $units),
            'database' => self::database($artifacts, $start, $end, $wallOffset),
            'leases' => self::leases($units, $start, $end),
            'web' => self::web($artifacts, $start, $end),
            // From the first warm-up encode to the run's end: the idle single encodes afterwards
            // are the drill's own commands, not launches of the native daemon.
            'killOrder' => array_map(
                static fn(string $unit): array => KillOrderEvidence::processes(
                    Reports::window(self::sampleRecords($units[$unit]), Reports::number($client['warmUp'][0]['startedAt'] ?? $start), $end),
                    $unit,
                ),
                ['control' => 'control', 'execution' => 'execution'],
            ),
            'singleEncode' => self::singleEncode($artifacts),
        ];
        $saved = is_file($artifacts . '/governor-state.json') ? Reports::json($artifacts . '/governor-state.json') : [];
        $learned = $saved['governor']['model']['samples'] ?? [];
        $receipt['governor']['savedAfterRun'] = ['state' => $saved['governor']['state'] ?? null, 'samples' => count($learned),
            'hardwareSamples' => count(array_filter($learned, static fn(array $sample): bool => ($sample['hardware_accelerated'] ?? false) === true))];
        $receipt['summary'] = self::summary($receipt);
        return $receipt;
    }

    /**
     * @param array<string,mixed> $client
     * @param list<array<string,mixed>> $records
     * @param list<string> $runJobs
     * @return array<string,mixed>
     */
    public static function governor(array $client, array $records, array $runJobs): array
    {
        $decisions = ['run' => [], 'warmUp' => []];
        $vetoes = [];
        $samples = [];
        $warmJobs = array_flip($client['warmUpJobs']);
        $run = array_flip($runJobs);
        foreach ($records as $record) {
            $context = $record['context'] ?? [];
            $job = is_array($context) ? ($context['jobId'] ?? null) : null;
            if (!is_string($job)) {
                continue;
            }
            $phase = isset($run[$job]) ? 'run' : (isset($warmJobs[$job]) ? 'warmUp' : null);
            $message = $record['message'] ?? '';
            if ($message === 'StreamAdmission: allocated stream' && $phase !== null) {
                $decisions[$phase][] = array_intersect_key($context, array_flip(['jobId', 'governorState', 'learningSamples', 'requestedTier', 'allowedTier', 'predictedCost', 'hardwareAccelerated']));
            } elseif ($message === 'StreamAdmission: vetoed stream') {
                $vetoes[] = $context + ['phase' => $phase];
            } elseif ($message === 'StreamAdmission: recorded encoder cost sample' && $phase === 'run') {
                $samples[] = array_intersect_key($context, array_flip(['jobId', 'cost', 'encoderCpuSeconds', 'outputSeconds', 'hardwareAccelerated', 'totalSamples', 'governorState']));
            }
        }
        return [
            'learningSamples' => $client['learningSamples'],
            'warmUpRounds' => count($client['warmUp']),
            'warmUpJobs' => count($client['warmUpJobs']),
            'beforeRun' => $client['governorBeforeRun'],
            'afterRun' => $client['governorAfterRun'],
            'runDecisions' => $decisions['run'],
            'warmUpDecisionsByState' => array_count_values(array_map(static fn(array $decision): string => (string) ($decision['governorState'] ?? 'unrecorded'), $decisions['warmUp'])),
            'vetoes' => $vetoes,
            'runCostSamples' => $samples,
        ];
    }

    /**
     * Each stream's speed is output seconds per wall second: over its whole life from admission
     * and, as its steady figure, from three seconds of output to its completion. A speed above one
     * runs ahead of real time by the margin.
     *
     * @param array<string,mixed> $client
     * @return array<string,mixed>
     */
    public static function video(array $client, string $pass): array
    {
        $streams = [];
        foreach ($client['streams'] as $stream) {
            $arguments = $stream['launch']['argv'] ?? [];
            $codec = ($index = array_search('-c:v', $arguments, true)) === false ? null : ($arguments[$index + 1] ?? null);
            $timeline = $stream['timeline'];
            $output = $timeline === [] ? null : (float) $timeline[array_key_last($timeline)][1];
            $warm = array_values(array_filter($timeline, static fn(array $point): bool => $point[1] >= 3.0));
            $steady = count($warm) >= 2 && $warm[array_key_last($warm)][0] > $warm[0][0]
                ? ($warm[array_key_last($warm)][1] - $warm[0][1]) / ($warm[array_key_last($warm)][0] - $warm[0][0]) : null;
            $whole = $output === null ? null : $output / max(.001, $stream['completedAt'] - $stream['admittedAt']);
            $streams[] = [
                'job' => $stream['job'],
                'codec' => $codec,
                'path' => $codec === null ? null : (array_any(self::HARDWARE_ENCODERS, static fn(string $suffix): bool => str_ends_with($codec, $suffix)) ? 'hardware' : 'software'),
                'admittedAt' => $stream['admittedAt'],
                'firstOutputAt' => $stream['firstOutputAt'],
                'completedAt' => $stream['completedAt'],
                'outputSeconds' => $output,
                'wholeSpeed' => $whole === null ? null : round($whole, 3),
                'steadySpeed' => $steady === null ? null : round($steady, 3),
                'realTimeMargin' => $steady === null ? null : round($steady - 1, 3),
                'progressReports' => ['sent' => $stream['progressSent'], 'rejected' => count($stream['progressRejected'])],
                'timeline' => self::downsample($timeline, 5.0),
            ];
        }
        $steady = array_values(array_filter(array_column($streams, 'steadySpeed'), is_float(...)));
        return [
            'expectedPath' => $pass,
            'streams' => $streams,
            'steadySpeed' => self::spread($steady),
            'realTimeMargin' => $steady === [] ? null : self::spread(array_map(static fn(float $speed): float => round($speed - 1, 3), $steady)),
            'streamsAheadOfRealTime' => count(array_filter($steady, static fn(float $speed): bool => $speed > 1.0)),
        ];
    }

    /**
     * @param array<string,mixed> $client
     * @return array<string,mixed>
     */
    public static function audio(array $client): array
    {
        $seconds = Reports::number($client['source']['trackSeconds']);
        $renditions = [];
        foreach ($client['renditions'] as $rendition) {
            $wall = $rendition['endedAt'] - $rendition['startedAt'];
            $renditions[] = ['lane' => $rendition['lane'], 'index' => $rendition['index'], 'format' => $rendition['format'], 'bitrate' => $rendition['bitrate'],
                'status' => $rendition['status'], 'bytes' => $rendition['bytes'],
                'startedAt' => round($rendition['startedAt'] - $client['runStartedAt'], 3), 'seconds' => round($wall, 3), 'speed' => round($seconds / max(.001, $wall), 2)];
        }
        $first = array_values(array_filter($renditions, static fn(array $rendition): bool => $rendition['index'] === 0));
        return [
            'lanes' => count(array_unique(array_column($renditions, 'lane'))),
            'firstWave' => ['count' => count($first), 'ok' => count(array_filter($first, static fn(array $rendition): bool => $rendition['status'] === 200 && $rendition['bytes'] > 0)),
                'speed' => $first === [] ? null : self::spread(array_column($first, 'speed'))],
            'renditions' => count($renditions),
            'failed' => count(array_filter($renditions, static fn(array $rendition): bool => $rendition['status'] !== 200 || $rendition['bytes'] === 0)),
            'speed' => $renditions === [] ? null : self::spread(array_column($renditions, 'speed')),
            'all' => $renditions,
        ];
    }

    /**
     * @param array<string,mixed> $client
     * @return array<string,mixed>
     */
    public static function occupancy(array $client): array
    {
        [$video, $audio] = [$client['profile']['videoEncoderSlots'], $client['profile']['audioEncoderSlots']];
        $samples = $client['occupancy'];
        $full = array_values(array_filter($samples, static fn(array $sample): bool => $sample['claimedVideo'] >= $video && $sample['runningAudio'] >= $audio));
        return [
            'samples' => count($samples),
            'peakClaimedVideo' => $samples === [] ? 0 : max(array_column($samples, 'claimedVideo')),
            'peakRunningAudio' => $samples === [] ? 0 : max(array_column($samples, 'runningAudio')),
            'everySlotSamples' => count($full),
            'firstEverySlotAt' => $full[0]['t'] ?? null,
        ];
    }

    /**
     * @param list<string> $runJobs
     * @return array<string,mixed>
     */
    public static function launches(string $artifacts, array $runJobs): array
    {
        $verification = Reports::json($artifacts . '/native-verification.json');
        $byJob = [];
        foreach (Reports::samples($artifacts . '/native-launches.jsonl') as $record) {
            $byJob[$record['jobId']] = ($byJob[$record['jobId']] ?? 0) + 1;
        }
        return [
            'totalVideoLaunches' => $verification['totalVideoLaunches'],
            'overlappingLaunches' => $verification['overlappingLaunches'],
            'duplicateGenerations' => $verification['duplicateGenerations'],
            'runJobLaunches' => array_map(static fn(string $job): int => $byJob[$job] ?? 0, $runJobs),
        ];
    }

    /** @return array<string,mixed> */
    public static function nativeProbe(string $artifacts, float $start, float $end): array
    {
        $probes = Reports::samples($artifacts . '/native-probe.jsonl');
        $run = array_values(array_filter($probes, static fn(array $probe): bool => $start <= $probe['t'] && $probe['t'] <= $end));
        $seconds = static fn(array $probes): array => array_values(array_filter(array_column($probes, 'seconds'), is_numeric(...)));
        return [
            'deadlineSeconds' => self::NATIVE_RPC_DEADLINE_SECONDS,
            'probes' => count($probes),
            'errors' => array_values(array_filter($probes, static fn(array $probe): bool => isset($probe['error']))),
            'connectToAnswer' => $seconds($probes) === [] ? null : Reports::timings($seconds($probes)),
            'connectToAnswerDuringRun' => $seconds($run) === [] ? null : Reports::timings($seconds($run)),
        ];
    }

    /**
     * Native failures and containment requests are failures wherever they appear; other errors
     * are listed for inspection.
     *
     * @param array<string,list<array<string,mixed>>> $logs
     * @return array<string,mixed>
     */
    public static function failureRecords(array $logs, string $artifacts): array
    {
        $native = [];
        $containment = [];
        $other = [];
        foreach ($logs as $unit => $records) {
            foreach ($records as $record) {
                if ((int) ($record['level'] ?? 0) < 400) {
                    continue;
                }
                $text = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $entry = ['unit' => $unit, 'message' => $record['message'] ?? '', 'datetime' => $record['datetime'] ?? null];
                if (str_contains($text, 'containment is required')) {
                    $containment[] = $entry;
                } elseif (str_contains($text, 'NativeExecutionException') || str_contains($text, 'Native execution')) {
                    $native[] = $entry + ['detail' => substr($text, 0, 600)];
                } else {
                    $key = $unit . ': ' . $entry['message'];
                    $other[$key] = ($other[$key] ?? 0) + 1;
                }
            }
        }
        foreach (['control', 'execution'] as $unit) {
            if (str_contains(Reports::text($artifacts . '/' . $unit . '-unit.log'), 'worker_supervisor_stopped')) {
                $containment[] = ['unit' => $unit, 'message' => 'worker_supervisor_stopped', 'datetime' => null];
            }
        }
        return ['nativeFailures' => $native, 'containment' => $containment, 'otherErrors' => $other];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $units
     * @return array<string,mixed>
     */
    public static function oom(string $artifacts, array $units): array
    {
        $containers = [];
        foreach ([...Reports::json($artifacts . '/containers-before.json'), ...Reports::json($artifacts . '/services-before.json')] as $container) {
            $containers[$container['Id']] = ltrim($container['Name'], '/');
        }
        $events = array_values(array_filter(
            Reports::samples($artifacts . '/oom-events.jsonl', EvidenceMode::Live),
            static fn(array $event): bool => isset($containers[$event['Actor']['ID'] ?? $event['id'] ?? '']),
        ));
        $kills = [];
        foreach ($units as $unit => $samples) {
            $counts = array_map(static fn(array $sample): int => Reports::counters((string) $sample['memory.events'])['oom_kill'] ?? 0, self::sampleRecords($samples));
            $kills[$unit] = $counts === [] ? null : max($counts) - min($counts);
        }
        $killed = [];
        foreach ([...Reports::json($artifacts . '/containers-after.json'), ...Reports::json($artifacts . '/services-after.json')] as $container) {
            if ($container['State']['OOMKilled'] ?? false) {
                $killed[] = ltrim($container['Name'], '/');
            }
        }
        return ['dockerEvents' => count($events), 'events' => $events, 'unitOomKills' => $kills, 'oomKilledContainers' => $killed];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $units
     * @return array<string,mixed>
     */
    public static function containment(string $artifacts, array $units): array
    {
        $after = [];
        foreach (Reports::json($artifacts . '/containers-after.json') as $container) {
            $after[ltrim($container['Name'], '/')] = ['running' => $container['State']['Running'], 'restarts' => $container['RestartCount'] ?? 0];
        }
        $draining = 0;
        $epochs = [];
        foreach ($units as $samples) {
            foreach (self::sampleRecords($samples) as $sample) {
                foreach ($sample['heartbeats'] ?? [] as $peer => $heartbeat) {
                    if (is_array($heartbeat)) {
                        $draining += $heartbeat['draining'] ? 1 : 0;
                        $epochs[$peer][$heartbeat['epoch']] = true;
                    }
                }
            }
        }
        return [
            'unitsAfterRun' => $after,
            'drainingHeartbeats' => $draining,
            'leaseEpochs' => array_map(static fn(array $values): array => array_keys($values), $epochs),
        ];
    }

    /**
     * PostgreSQL backends by origin, against the full profile's pools and direct reserve, and the
     * pooler's own view of each pool.
     *
     * @return array<string,mixed>
     */
    public static function database(string $artifacts, float $start, float $end, float $wallOffset): array
    {
        $pooler = null;
        foreach (Reports::json($artifacts . '/services-before.json') as $container) {
            if (str_ends_with($container['Name'], '-pooler')) {
                foreach ($container['NetworkSettings']['Networks'] as $network) {
                    $pooler ??= $network['IPAddress'];
                }
            }
        }
        Reports::require(is_string($pooler) && $pooler !== '', 'The pooler address is missing');
        $samples = self::databaseSamples(Reports::text($artifacts . '/database-samples.txt'), $pooler, $wallOffset);
        $pools = Pooler::defaultPools();
        $limits = ['application' => $pools['application'], 'control' => $pools['control'], 'direct' => $pools['direct'],
            'total' => $pools['application'] + $pools['control'] + $pools['direct'], 'maxConnections' => Pooler::maxConnections($pools)];
        $peak = static fn(array $samples, string $key): int => $samples === [] ? 0 : max(array_map(static fn(array $sample): int => $sample['backends'][$key], $samples));
        $run = array_values(array_filter($samples, static fn(array $sample): bool => $start <= $sample['monotonic'] && $sample['monotonic'] <= $end));
        $poolPeak = static function (array $samples, string $database, string $field): int {
            $values = array_map(static fn(array $sample): int => (int) ($sample['pools'][$database][$field] ?? 0), $samples);
            return $values === [] ? 0 : max($values);
        };
        $poolWait = static function (array $samples, string $database): float {
            $values = array_map(static fn(array $sample): float => (int) ($sample['pools'][$database]['maxwait'] ?? 0) + (int) ($sample['pools'][$database]['maxwait_us'] ?? 0) / 1e6, $samples);
            return $values === [] ? 0.0 : round(max($values), 3);
        };
        $saturated = array_filter($run, static fn(array $sample): bool => (int) ($sample['pools']['worker_operator_test']['sv_active'] ?? 0) >= $pools['application']
            && (int) ($sample['pools']['worker_operator_test']['cl_waiting'] ?? 0) > 0);
        return [
            'poolerAddress' => $pooler,
            'limits' => $limits,
            'samples' => count($samples),
            'runSamples' => count($run),
            'errors' => array_sum(array_column($samples, 'errors')),
            'peak' => ['application' => $peak($samples, 'application'), 'control' => $peak($samples, 'control'), 'direct' => $peak($samples, 'direct'), 'total' => $peak($samples, 'total')],
            'pools' => [
                'application' => ['peakActiveServers' => $poolPeak($run, 'worker_operator_test', 'sv_active'), 'peakWaitingClients' => $poolPeak($run, 'worker_operator_test', 'cl_waiting'),
                    'peakWaitSeconds' => $poolWait($run, 'worker_operator_test'), 'saturatedRunSamples' => count($saturated)],
                'control' => ['peakActiveServers' => $poolPeak($run, 'worker_operator_test_control', 'sv_active'), 'peakWaitingClients' => $poolPeak($run, 'worker_operator_test_control', 'cl_waiting'),
                    'peakWaitSeconds' => $poolWait($run, 'worker_operator_test_control')],
            ],
            'timeline' => self::downsample(array_map(static fn(array $sample): array => [round($sample['monotonic'] - $start, 1), $sample['backends']['total'],
                (int) ($sample['pools']['worker_operator_test']['cl_waiting'] ?? 0)], $samples), 10.0),
        ];
    }

    /**
     * Parses the database sampler's text: a "sample" line with the wall clock, then one
     * "backend|user|client|state|count" line per group and the pooler's SHOW POOLS as CSV lines
     * prefixed "pools|".
     *
     * @return list<array<string,mixed>>
     */
    public static function databaseSamples(string $text, string $pooler, float $wallOffset): array
    {
        $samples = [];
        $current = null;
        $header = null;
        foreach (explode("\n", $text) as $line) {
            if (str_starts_with($line, 'sample ')) {
                if ($current !== null) {
                    $samples[] = $current;
                }
                $current = ['monotonic' => Reports::number(substr($line, 7)) - $wallOffset, 'errors' => 0,
                    'backends' => ['application' => 0, 'control' => 0, 'direct' => 0, 'total' => 0], 'pools' => []];
                $header = null;
            } elseif ($current === null) {
                continue;
            } elseif (str_starts_with($line, 'backend|')) {
                [, $user, $client, , $count] = explode('|', $line);
                $kind = match (true) {
                    $user === 'baander' . Pooler::CONTROL_SUFFIX => 'control',
                    $client === $pooler => 'application',
                    default => 'direct',
                };
                $current['backends'][$kind] += (int) $count;
                $current['backends']['total'] += (int) $count;
            } elseif (str_starts_with($line, 'pools|')) {
                $fields = str_getcsv(substr($line, 6), escape: '');
                if ($header === null) {
                    $header = $fields;
                } elseif (count($fields) === count($header)) {
                    $row = array_combine($header, $fields);
                    $current['pools'][$row['database']] = $row;
                }
            } elseif (str_starts_with($line, 'error ')) {
                ++$current['errors'];
            }
        }
        if ($current !== null) {
            $samples[] = $current;
        }
        return $samples;
    }

    /**
     * Each lease helper's lifetime from its start until its supervisor reaped it, against the
     * supervisor's deadline, for the whole deployment and for the run.
     *
     * @param array<string,list<array<string,mixed>>> $units
     * @return array<string,mixed>
     */
    public static function leases(array $units, float $start, float $end): array
    {
        $result = ['deadlineSeconds' => self::LEASE_DEADLINE_SECONDS];
        foreach ($units as $unit => $records) {
            $samples = self::sampleRecords($records);
            // The helpers' clock is the boot clock; each sample pairs it with the monotonic clock.
            $offset = $samples === [] ? 0.0 : Reports::number($samples[0]['uptime']) - Reports::number($samples[0]['monotonic']);
            $agents = array_values(array_filter($records, static fn(array $record): bool => ($record['type'] ?? null) === 'leaseAgent'));
            $run = array_values(array_filter($agents, static fn(array $agent): bool => $start <= $agent['reapedBy'] - $offset && $agent['reapedBy'] - $offset <= $end));
            $result[$unit] = [
                'helpers' => count($agents),
                'actions' => array_count_values(array_map(static fn(array $agent): string => (string) $agent['action'], $agents)),
                'lifetime' => $agents === [] ? null : Reports::timings(array_column($agents, 'seconds')),
                'runHelpers' => count($run),
                'runLifetime' => $run === [] ? null : Reports::timings(array_column($run, 'seconds')),
                'overDeadline' => array_values(array_filter($agents, static fn(array $agent): bool => $agent['seconds'] >= self::LEASE_DEADLINE_SECONDS)),
            ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public static function web(string $artifacts, float $start, float $end): array
    {
        $requests = array_values(array_filter(
            Reports::samples($artifacts . '/web-latency.jsonl'),
            static fn(array $request): bool => $start <= $request['t'] && $request['t'] <= $end,
        ));
        $ok = array_values(array_filter($requests, static fn(array $request): bool => $request['status'] === 200 && $request['curl'] === 0));
        $seconds = array_column($ok, 'seconds');
        sort($seconds);
        $rank = static fn(float $rank): ?float => $seconds === [] ? null : round(Reports::percentile($seconds, $rank), 4);
        return [
            'requests' => count($requests),
            'failed' => count($requests) - count($ok),
            'failedStatuses' => array_count_values(array_map(
                static fn(array $request): string => $request['status'] . '/' . $request['curl'],
                array_filter($requests, static fn(array $request): bool => $request['status'] !== 200 || $request['curl'] !== 0),
            )),
            'p50Seconds' => $rank(.5), 'p95Seconds' => $rank(.95), 'p99Seconds' => $rank(.99), 'maxSeconds' => $rank(1.0),
        ];
    }

    /** @return array<string,mixed> */
    public static function singleEncode(string $artifacts): array
    {
        $receipt = MediaCost::hardwareReceipt($artifacts . '/single-samples.jsonl');
        $encoders = [];
        foreach ($receipt['steps'] as $step) {
            $encoders[$step['encoder']] = ['speed' => $step['speed']['minimum'] ?? null, 'cpuSecondsPerOutputSecond' => $step['cpuSecondsPerOutputSecond'] ?? null,
                'aheadOfRealTime' => $step['everyStreamAheadOfRealTime'], 'loadavg' => $step['window']['loadavg'] ?? null];
        }
        return ['host' => $receipt['host'], 'encoders' => $encoders];
    }

    /**
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private static function summary(array $receipt): array
    {
        $paths = array_count_values(array_map(static fn(array $stream): string => (string) $stream['path'], $receipt['video']['streams']));
        return [
            'slots' => ['video' => $receipt['profile']['videoEncoderSlots'], 'audio' => $receipt['profile']['audioEncoderSlots']],
            'videoCompleted' => count(array_filter($receipt['video']['streams'], static fn(array $stream): bool => $stream['completedAt'] !== null)),
            'encodePaths' => $paths,
            'videoSteadySpeed' => $receipt['video']['steadySpeed'],
            'audioFirstWaveSpeed' => $receipt['audio']['firstWave']['speed'],
            'governor' => ['beforeRun' => $receipt['governor']['beforeRun'], 'runDecisionStates' => array_count_values(array_map(static fn(array $decision): string => (string) ($decision['governorState'] ?? 'unrecorded'), $receipt['governor']['runDecisions']))],
            'occupancy' => $receipt['occupancy'],
            'oom' => ['dockerEvents' => $receipt['oom']['dockerEvents'], 'unitOomKills' => $receipt['oom']['unitOomKills']],
            'containment' => count($receipt['failures']['containment']),
            'nativeFailures' => count($receipt['failures']['nativeFailures']),
            'backends' => ['peak' => $receipt['database']['peak'], 'limits' => $receipt['database']['limits']],
            'applicationPoolSaturation' => ['saturatedRunSamples' => $receipt['database']['pools']['application']['saturatedRunSamples'], 'runSamples' => $receipt['database']['runSamples']],
            'leaseLifetimeMaxSeconds' => ['control' => $receipt['leases']['control']['lifetime']['maxSeconds'] ?? null, 'execution' => $receipt['leases']['execution']['lifetime']['maxSeconds'] ?? null],
            'nativeConnectToAnswerMaxSeconds' => $receipt['native']['connectToAnswer']['maxSeconds'] ?? null,
            'web' => array_intersect_key($receipt['web'], array_flip(['requests', 'failed', 'p50Seconds', 'p95Seconds'])),
            'singleEncode' => $receipt['singleEncode']['encoders'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function logRecords(string $path): array
    {
        $records = [];
        foreach (explode("\n", is_file($path) ? Reports::text($path) : '') as $line) {
            $record = $line === '' ? null : json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }
        return $records;
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private static function sampleRecords(array $records): array
    {
        return array_values(array_filter($records, static fn(array $record): bool => ($record['type'] ?? null) === 'sample'));
    }

    /**
     * @param list<int|float> $values
     * @return array{minimum:float,median:float,maximum:float}|null
     */
    private static function spread(array $values): ?array
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        return ['minimum' => round((float) $values[0], 3), 'median' => round((float) Reports::median($values), 3), 'maximum' => round((float) $values[count($values) - 1], 3)];
    }

    /**
     * Keeps the first point of every interval of the first column, and the last point.
     *
     * @param list<list<int|float>> $points
     * @return list<list<int|float>>
     */
    private static function downsample(array $points, float $interval): array
    {
        $kept = [];
        $next = -INF;
        foreach ($points as $index => $point) {
            if ($point[0] >= $next || $index === array_key_last($points)) {
                $kept[] = $point;
                $next = $point[0] + $interval;
            }
        }
        return $kept;
    }

    private const string DATABASE_SAMPLER = <<<'SH'
        end=$(( $(date +%s) + $1 ))
        while [ "$(date +%s)" -lt "$end" ]; do
            printf 'sample %s\n' "$(date +%s.%N)"
            psql -X -q -At -h 127.0.0.1 -U baander -d worker_operator_test -c "SELECT 'backend|' || usename || '|' || coalesce(host(client_addr), 'local') || '|' || coalesce(state, '') || '|' || count(*) FROM pg_stat_activity WHERE backend_type = 'client backend' GROUP BY usename, client_addr, state" || printf 'error backends\n'
            psql -X -q --csv -h pooler -p 6432 -U baander -d pgbouncer -c 'SHOW POOLS' | sed 's/^/pools|/' || printf 'error pools\n'
            if [ -n "${BAANDER_TEST_SAMPLER_STOP_FILE:-}" ] && [ -e "$BAANDER_TEST_SAMPLER_STOP_FILE" ]; then
                break
            fi
            sleep 0.5
        done
        SH;

    private const string SATURATOR = <<<'SH'
        printf 'SELECT pg_sleep(0.05);\n' > /tmp/load-saturator.sql
        pgbench -n -h pooler -p 6432 -U baander -c "$1" -j 4 -T "$2" -P 10 -f /tmp/load-saturator.sql worker_operator_test &
        echo $! > /tmp/load-saturator.pid
        wait $!
        SH;
}
