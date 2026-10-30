<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{EngineExecutor, ExecutionContext, ProcessSpec, CompletionPolicy};
use Baander\Tooling\Fixtures\Worker\{AutoscalingVerifier, ControlCapacityVerifier, MediaControlVerifier, Reports, EvidenceMode};
use Baander\Tooling\Configuration\ApplicationLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerFixtureTest extends TestCase
{
    private string $root;
    /** @var list<array<string,mixed>> */
    private array $values = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-worker-verifiers-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        (new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3))))->run(new ProcessSpec(['rm', '-rf', '--', $this->root]));
    }

    /** @param array<mixed> $data */
    private function json(string $name, array $data): void
    {
        Reports::publish($this->root . '/' . $name, $data);
    }

    /** @param list<array<string,mixed>> $values */
    private function lines(string $name, array $values): void
    {
        $lines = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR) . "\n", $values);
        file_put_contents($this->root . '/' . $name, implode('', $lines));
    }

    /** @return array<string,mixed> */
    private static function gap(): array
    {
        $health = static function (float $at): array {
            $peers = [];
            foreach (['control', 'execution'] as $unit) {
                $peers[$unit] = [
                    'namespace' => 'qualification',
                    'bootId' => 'boot',
                    'epoch' => 1,
                    'unit' => $unit,
                    'ready' => true,
                    'draining' => false,
                    'time' => $at - .1,
                ];
            }
            return [
                'sampledAt' => $at,
                'lease' => ['owner_boot_id' => 'boot', 'epoch' => 1, 'state' => 'active', 'unexpired' => true],
                'peers' => $peers,
            ];
        };
        return [
            'namespace' => 'qualification',
            'bootId' => 'boot',
            'epoch' => 1,
            'controller' => ['pid' => 100, 'startTicks' => '42', 'role' => 'autoscaler', 'generation' => 1],
            'startedAt' => 100,
            'stoppedAt' => 100.1,
            'publishedAt' => 103.4,
            'staleAt' => 104.6,
            'resumedAt' => 104.7,
            'recoveredAt' => 108,
            'finishedAt' => 108.1,
            'maxSampleAgeSeconds' => 3,
            'messageId' => '1000-0',
            'unreadProof' => ['present' => 1, 'pending' => 0, 'lastDeliveredId' => '999-0', 'unread' => 1],
            'staleStatus' => [
                'state' => 'unavailable',
                'authorityValid' => true,
                'controllerFresh' => false,
                'controllerAgeSeconds' => 4.6,
            ],
            'recoveredStatus' => [
                'state' => 'known',
                'authorityValid' => true,
                'controllerFresh' => true,
                'controllerAgeSeconds' => .1,
            ],
            'staleHealth' => $health(104.6),
            'recoveredHealth' => $health(108),
            'completion' => [
                'batch' => 'observation-gap',
                'sequence' => 0,
                'started' => 106,
                'finished' => 108,
                'pid' => 200,
                'slot' => 'consumer-0',
            ],
        ];
    }

    private function autoscalingFixture(): void
    {
        $this->json('gap.json', self::gap());
        $completed = [];
        foreach (['cold' => 1, 'repeat' => 150, 'cpu' => 250] as $batch => $start) {
            $count = $batch === 'cpu' ? 16 : 48;
            $kind = $batch === 'cpu' ? 'cpu' : 'io';
            $this->json($batch . '.json', ['started' => $start, 'count' => $count, 'kind' => $kind]);
            for ($index = 0; $index < $count; ++$index) {
                $completed[] = [
                    'batch' => $batch,
                    'sequence' => $index,
                    'started' => $start + $index * 2,
                    'finished' => $start + ($index + 1) * 2,
                    'kind' => $kind,
                    'cpuSeconds' => $batch === 'cpu' ? 1.8 : .001,
                    'pid' => 99,
                    'slot' => 'consumer-0',
                ];
            }
        }
        for ($at = 0; $at < 400; ++$at) {
            $peers = [];
            foreach (['control', 'execution'] as $unit) {
                $peers[$unit] = [
                    'namespace' => 'qualification',
                    'bootId' => 'boot',
                    'epoch' => 1,
                    'unit' => $unit,
                    'ready' => true,
                    'draining' => false,
                    'time' => $at,
                ];
            }
            $processes = [];
            for ($index = 0; $index < 4; ++$index) {
                $processes[] = [
                    'pid' => $index + 1,
                    'startTicks' => '100',
                    'rssBytes' => 4096,
                    'cmdline' => "/usr/bin/ffmpeg\0-i\0/media/source/" . ($index < 3 ? 'video.mkv' : 'track.flac') . "\0",
                ];
            }
            $receipts = array_filter($completed, static fn(array $receipt): bool => $receipt['finished'] <= $at);
            $this->values[] = [
                'monotonic' => $at,
                'wallTime' => 1000 + $at,
                'lease' => ['owner_boot_id' => 'boot', 'epoch' => 1, 'state' => 'active', 'unexpired' => true],
                'admission' => [
                    'namespace' => 'qualification',
                    'bootId' => 'boot',
                    'epoch' => 1,
                    'target' => $at >= 390 ? 1 : 2,
                    'fatal' => false,
                    'controllerAt' => $at,
                    'slots' => ['0' => ['requestId' => null, 'state' => 'parked'], '1' => ['requestId' => null, 'state' => 'parked']],
                    'evidence' => [
                        'durationSeconds' => 2,
                        'cpuUtilization' => ($at >= 255 && $at <= 282) || ($at >= 305 && $at <= 360) ? .95 : .1,
                        'queueState' => 'known',
                        'unreadCount' => 0,
                        'pendingCount' => 0,
                        'dueCount' => 0,
                        'resourceState' => 'known',
                        'queueSampleAgeSeconds' => 0,
                        'resourceSampleAgeSeconds' => 0,
                    ],
                    'reason' => $at >= 305 && $at <= 360 ? 'resource_growth_blocked' : 'stable',
                ],
                'completions' => implode(
                    '',
                    array_map(static fn(array $receipt): string => json_encode($receipt, JSON_THROW_ON_ERROR) . "\n", $receipts),
                ),
                ...$peers,
                'resource' => [
                    'namespace' => 'qualification',
                    'bootId' => 'boot',
                    'epoch' => 1,
                    'observation' => [
                        'state' => 'known',
                        'sampledAt' => $at,
                        'cpuUtilization' => .1,
                        'memoryRatio' => .3,
                        'pidsRatio' => .1,
                    ],
                ],
                'status' => [
                    'state' => 'known',
                    'authorityValid' => true,
                    'controllerFresh' => true,
                    'resourceFresh' => true,
                    'controllerAgeSeconds' => 0,
                    'resources' => ['state' => 'known'],
                ],
                'connections' => 10,
                'memory.current' => '1234',
                'pids.current' => '4',
                'cpu.stat' => 'usage_usec ' . (200 + $at * 2_000_000),
                'processes' => $processes,
            ];
        }
        $backlog = [];
        for ($index = 0; $index <= 60; ++$index) {
            $backlog[] = [
                'monotonic' => 300 + $index,
                'receiverDepth' => 2000,
                'acknowledged' => $index,
                'relayedEvents' => $index,
            ];
        }
        $this->json(
            'media.json',
            ['evidence' => [
                'videoJobs' => ['one', 'two', 'three'],
                'audioBytes' => 8192,
                'desiredStateSamples' => [['seconds' => .5]],
                'pressureStartedAt' => 300,
                'pressureEndedAt' => 360,
                'ordinaryBacklog' => $backlog,
                'dueOccurrences' => array_fill(
                    0,
                    4,
                    ['execution' => [
                        'deployment_namespace' => 'qualification',
                        'deployment_boot_id' => 'boot',
                        'deployment_epoch' => 1,
                        'returned' => true,
                        'completion_seconds' => 5,
                    ]],
                ),
            ]],
        );
        $this->json(
            'containers-before.json',
            array_fill(0, 2, ['Created' => '1970-01-01T00:15:00Z', 'State' => ['StartedAt' => '1970-01-01T00:15:50Z']]),
        );
        $this->writeValues();
    }

    private function writeValues(): void
    {
        foreach (['control', 'execution'] as $unit) {
            $this->lines($unit . '-samples.jsonl', $this->values);
        }
    }

    public function testCompleteAutoscalingEvidenceProducesExpectedMetrics(): void
    {
        $this->autoscalingFixture();
        $result = AutoscalingVerifier::verify($this->root, 2);
        self::assertSame(400, $result['samples']);
        self::assertSame(96, $result['bursts'][0]['seconds']);
        self::assertSame(64.0, $result['ordinaryCpu']['executionCpuSeconds']);
        self::assertSame(4, $result['nativePressure']['minimumProcesses']);
        self::assertSame(['known' => 400, 'unknown' => 0, 'unavailable' => 0], $result['resourceStates']);
        self::assertFileExists($this->root . '/qualification.json');
    }

    /** @return iterable<string,array{string}> */
    public static function invalidAutoscaling(): iterable
    {
        foreach ([
            'missing-completion',
            'overadmission',
            'foreign-lease',
            'stalled-control',
            'unmeasured-pressure',
            'stale-controller',
            'stale-status',
            'truncated-execution',
            'gapped-execution',
            'missing-native',
            'stale-resource',
            'foreign-peer',
            'stale-known-status',
            'short-pressure',
            'pressure-boundary',
            'lease-publication',
            'missing-pressure-metric',
            'video-disappeared',
            'audio-disappeared',
            'missing-cpu',
            'fake-cpu',
            'foreign-scheduler',
            'unreturned-scheduler',
            'stalled-backlog',
            'oscillation',
            'missing-cgroup-cpu',
            'cpu-growth',
            'scheduler-deadline',
            'stalled-outbox',
            'idle-unread',
            'idle-pending',
            'idle-due',
            'idle-unknown',
            'idle-stale',
            'pressure-growth',
            'malformed-memory',
            'malformed-duration',
        ] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidAutoscaling')]
    public function testInvalidAutoscalingEvidenceRejectsQualification(string $case): void
    {
        $this->autoscalingFixture();
        $media = Reports::json($this->root . '/media.json');
        switch ($case) {
            case 'malformed-memory':
                $this->values[200]['memory.current'] = 'invalid';
                break;
            case 'malformed-duration':
                $media['evidence']['dueOccurrences'][0]['execution']['completion_seconds'] = 'invalid';
                break;
            case 'missing-completion':
                foreach ($this->values as &$value) {
                    $value['completions'] = '';
                }
                unset($value);
                break;
            case 'overadmission': $this->values[10]['admission']['target'] = 3;
                break;
            case 'foreign-lease': $this->values[10]['lease']['epoch'] = 2;
                break;
            case 'stalled-control': $this->values[10]['monotonic'] = 20;
                break;
            case 'unmeasured-pressure':
                foreach ($this->values as &$value) {
                    $value['admission']['reason'] = 'stable';
                }
                unset($value);
                break;
            case 'stale-controller': $this->values[399]['admission']['controllerAt'] = 0;
                break;
            case 'stale-status': $this->values[200]['status']['state'] = 'unavailable';
                break;
            case 'missing-native': $this->values[320]['processes'] = [['pid' => 1]];
                break;
            case 'stale-resource': $this->values[200]['resource']['observation']['sampledAt'] = 190;
                break;
            case 'foreign-peer': $this->values[200]['execution']['epoch'] = 2;
                break;
            case 'stale-known-status': $this->values[200]['status']['resourceFresh'] = false;
                break;
            case 'short-pressure': $media['evidence']['pressureEndedAt'] = 350;
                break;
            case 'lease-publication':
                foreach ($this->values as &$value) {
                    $value['lease']['owner_boot_id'] = 'foreign';
                }
                unset($value);
                break;
            case 'missing-pressure-metric': $this->values[200]['resource']['observation']['cpuUtilization'] = null;
                break;
            case 'video-disappeared': $this->values[320]['processes'] = [$this->values[320]['processes'][3]];
                break;
            case 'audio-disappeared': array_pop($this->values[320]['processes']);
                break;
            case 'missing-cpu':
            case 'fake-cpu':
                foreach ($this->values as &$value) {
                    $rows = [];
                    foreach (explode("\n", trim($value['completions'])) as $line) {
                        if ($line === '') {
                            continue;
                        }
                        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                        if ($row['batch'] === 'cpu') {
                            if ($case === 'missing-cpu') {
                                continue;
                            }
                            $row['cpuSeconds'] = .001;
                        }
                        $rows[] = json_encode($row, JSON_THROW_ON_ERROR) . "\n";
                    }
                    $value['completions'] = implode('', $rows);
                }
                unset($value);
                break;
            case 'foreign-scheduler': $media['evidence']['dueOccurrences'][0]['execution']['deployment_boot_id'] = 'foreign';
                break;
            case 'unreturned-scheduler': $media['evidence']['dueOccurrences'][0]['execution']['returned'] = false;
                break;
            case 'scheduler-deadline': $media['evidence']['dueOccurrences'][0]['execution']['completion_seconds'] = 11;
                break;
            case 'stalled-backlog':
            case 'stalled-outbox':
                foreach ($media['evidence']['ordinaryBacklog'] as &$row) {
                    $row[$case === 'stalled-backlog' ? 'acknowledged' : 'relayedEvents'] = 0;
                }
                unset($row);
                break;
            case 'oscillation': $this->values[10]['admission']['target'] = 1;
                break;
            case 'missing-cgroup-cpu':
                for ($at = 250; $at < 283; ++$at) {
                    $this->values[$at]['cpu.stat'] = 'usage_usec 500000000';
                }
                break;
            case 'cpu-growth':
                for ($at = 250; $at < 266; ++$at) {
                    $this->values[$at]['admission']['target'] = 1;
                }
                break;
            case 'pressure-growth':
                for ($at = 300; $at < 306; ++$at) {
                    $this->values[$at]['admission']['target'] = 1;
                }
                $this->values[305]['admission']['evidence']['cpuUtilization'] = .1;
                break;
            case 'idle-unread':
            case 'idle-pending':
            case 'idle-due':
            case 'idle-unknown':
            case 'idle-stale':
                for ($at = 397; $at < 400; ++$at) {
                    $key = match ($case) {
                        'idle-unread' => 'unreadCount', 'idle-pending' => 'pendingCount', 'idle-due' => 'dueCount', 'idle-unknown' => 'queueState', default => 'queueSampleAgeSeconds',
                    };
                    $this->values[$at]['admission']['evidence'][$key] = $case === 'idle-unknown' ? 'unknown' : ($case === 'idle-stale' ? 4 : 1);
                }
                break;
        }
        $this->writeValues();
        $this->json('media.json', $media);
        if ($case === 'truncated-execution') {
            $this->lines('execution-samples.jsonl', [$this->values[0]]);
        }
        if ($case === 'gapped-execution') {
            $this->lines(
                'execution-samples.jsonl',
                array_values(array_filter(
                    $this->values,
                    static fn(array $value): bool => $value['monotonic'] < 320 || $value['monotonic'] >= 330,
                )),
            );
        }
        if ($case === 'pressure-boundary') {
            $this->lines('execution-samples.jsonl', array_slice($this->values, 303));
        }
        $this->expectException(RuntimeException::class);
        AutoscalingVerifier::verify($this->root, 2);
    }

    public function testUnknownResourceAndRecoveredPressureAreClassified(): void
    {
        $this->autoscalingFixture();
        $this->values[200]['resource']['observation']['state'] = 'unknown';
        $this->values[200]['status']['resources']['state'] = 'unknown';
        $this->values[200]['status']['resourceFresh'] = false;
        for ($at = 300; $at < 306; ++$at) {
            $this->values[$at]['admission']['target'] = 1;
        }
        $this->values[306]['admission']['evidence']['cpuUtilization'] = .1;
        $this->writeValues();
        $result = AutoscalingVerifier::verify($this->root, 2);
        self::assertSame(1, $result['resourceStates']['unknown']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function corruptFinalEvidence(): iterable
    {
        foreach (['autoscaling', 'capacity'] as $verifier) {
            yield $verifier . ' interior corruption' => [$verifier, "{\n"];
            yield $verifier . ' incomplete final record' => [$verifier, '{'];
        }
    }

    #[DataProvider('corruptFinalEvidence')]
    public function testActualVerifiersRejectCorruptFinalMeasurementRecords(string $verifier, string $suffix): void
    {
        if ($verifier === 'autoscaling') {
            $this->autoscalingFixture();
        } else {
            $this->capacityFixture('memory');
        }
        $path = $this->root . '/control-samples.jsonl';
        if (str_ends_with($suffix, "\n")) {
            $contents = Reports::text($path);
            $at = strpos($contents, "\n") + 1;
            file_put_contents($path, substr($contents, 0, $at) . $suffix . substr($contents, $at));
        } else {
            file_put_contents($path, $suffix, FILE_APPEND);
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sample');
        if ($verifier === 'autoscaling') {
            AutoscalingVerifier::verify($this->root, 2);
        } else {
            ControlCapacityVerifier::verify($this->root, 'memory');
        }
    }

    public function testLiveReadingAcceptsOnlyUnterminatedTrailingWrites(): void
    {
        $this->autoscalingFixture();
        for ($at = 397; $at < 400; ++$at) {
            foreach ($this->values[$at]['admission']['slots'] as &$slot) {
                $slot = ['state' => 'receiving', 'requestId' => 'empty-poll'];
            }
            unset($slot);
        }
        $this->writeValues();
        file_put_contents($this->root . '/control-samples.jsonl', '{', FILE_APPEND);
        self::assertCount(400, AutoscalingVerifier::samples($this->root, mode: EvidenceMode::Live));
        AutoscalingVerifier::idle($this->root, EvidenceMode::Live);
        file_put_contents($this->root . '/control-samples.jsonl', "\n\n", FILE_APPEND);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed complete sample');
        Reports::samples($this->root . '/control-samples.jsonl', EvidenceMode::Live);
    }

    public function testMalformedInteriorJsonlStillFailsBeforeLaterEvidence(): void
    {
        $path = $this->root . '/evidence.jsonl';
        file_put_contents($path, "{\"monotonic\":0}\n{\n\n{\"monotonic\":1}\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed complete sample in evidence.jsonl');
        Reports::samples($path, EvidenceMode::Live);
    }

    public function testLargeCumulativeJsonlEvidenceFitsTheDefaultCliMemoryBudget(): void
    {
        $this->autoscalingFixture();
        $path = $this->root . '/cumulative.jsonl';
        $stream = fopen($path, 'wb');
        self::assertIsResource($stream);
        $completions = $this->values[399]['completions'];
        try {
            for ($index = 0; $index < 4608; ++$index) {
                $sample = ['monotonic' => $index / 4, 'completions' => $completions];
                fwrite($stream, json_encode($sample, JSON_THROW_ON_ERROR) . "\n");
            }
        } finally {
            fclose($stream);
        }
        $code = <<<'PHP_CODE'
            require $argv[1];
            $samples = Baander\Tooling\Fixtures\Worker\Reports::samples($argv[2]);
            echo json_encode(['count' => count($samples), 'first' => $samples[0], 'last' => $samples[array_key_last($samples)]], JSON_THROW_ON_ERROR);
            PHP_CODE;
        $result = new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)))->run(new ProcessSpec([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code, dirname(__DIR__, 2) . '/bootstrap.php', $path]), new CompletionPolicy(null));
        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);
        $observed = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(4608, $observed['count']);
        self::assertSame(['monotonic' => 0, 'completions' => $completions], $observed['first']);
        self::assertSame(['monotonic' => 1151.75, 'completions' => $completions], $observed['last']);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidGaps(): iterable
    {
        foreach (['consumed', 'falsely-fresh', 'missing-recovery', 'foreign-lease', 'overlong', 'advanced-delivery'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidGaps')]
    public function testInvalidGapReceiptRejected(string $case): void
    {
        $receipt = self::gap();
        match ($case) {
            'consumed' => $receipt['unreadProof']['pending'] = 1,
            'falsely-fresh' => $receipt['staleStatus']['controllerFresh'] = true,
            'missing-recovery' => $receipt['recoveredStatus']['state'] = 'unavailable',
            'foreign-lease' => $receipt['staleHealth']['lease']['owner_boot_id'] = 'foreign',
            'overlong' => $receipt['finishedAt'] = 126,
            'advanced-delivery' => $receipt['unreadProof']['lastDeliveredId'] = '1001-0',
            default => throw new \InvalidArgumentException('Unknown gap test case'),
        };
        $this->json('gap.json', $receipt);
        $this->expectException(RuntimeException::class);
        AutoscalingVerifier::gapReceipt($this->root);
    }

    /** @return array<string,mixed> */
    private function capacityFixture(string $phase = 'cpu'): array
    {
        $containers = [];
        foreach (['control', 'execution', 'postgres', 'redis', 'web'] as $index => $name) {
            $containers[] = [
                'Id' => $name,
                'Config' => ['Env' => ['BAANDER_WORKER_BOOT_ID=boot']],
                'Mounts' => [['Type' => 'volume', 'Name' => 'worker-ipc', 'Destination' => '/run/baander-worker']],
                'HostConfig' => [
                    'Memory' => ($index === 0 ? 2944 : ($index === 1 ? 4480 : 256)) * 1024 * 1024,
                    'PidsLimit' => $index === 0 ? 129 : 256,
                    'NanoCpus' => $index === 1 ? 2_000_000_000 : 1_000_000_000,
                    'CpusetCpus' => $index === 0 ? '1' : '2,3',
                ],
                'State' => ['Running' => true, 'OOMKilled' => false],
                'RestartCount' => 0,
            ];
        }
        $this->json('containers-before.json', $containers);
        $final = array_slice($containers, 0, 2);
        foreach ($final as &$unit) {
            $unit['State']['Running'] = false;
        }
        unset($unit);
        $this->json('drain.json', $final);
        $this->json('containers-after-pressure.json', array_slice($containers, 0, 2));
        $this->json('containers-after.json', array_slice($containers, 0, 2));
        file_put_contents($this->root . '/drain-seconds.txt', '2');
        file_put_contents($this->root . '/drain-trigger.txt', 'automatic-execution-exhaustion');
        $capture = [
            'containerIds' => ['control' => 'control', 'execution' => 'execution'],
            'captureExitCode' => 0,
            'monitorAliveAfterDrainAndCapture' => true,
            'monitorIntentionallyStopped' => true,
            'monitorExitCode' => 143,
            'sinceSource' => 'daemon-info-before-pressure',
            'untilSource' => 'daemon-info-after-drain',
            'sinceUnixSeconds' => 100,
            'untilUnixSeconds' => 200,
        ];
        $this->json('worker-oom-events-capture.json', $capture);
        $terminalHeartbeat = ['namespace' => 'worker.baander.app', 'bootId' => 'boot', 'unit' => 'control', 'epoch' => 1, 'ready' => false, 'draining' => true, 'time' => 65.01];
        $this->json('control-drain-heartbeat.json', $terminalHeartbeat);
        $this->json('control-drain-capture.json', ['containerId' => 'control', 'source' => 'control:/run/baander-worker/boot/control.json', 'copiedAfterStop' => true]);
        $this->lines('worker-oom-events-final.jsonl', []);
        $this->lines('worker-oom-events.jsonl', []);
        $this->values = [];
        $backlog = [];
        for ($at = 0; $at <= 65; ++$at) {
            $native = [];
            for ($index = 0; $index < 4; ++$index) {
                $native[] = ['argv' => ['/usr/bin/ffmpeg', '-i', '/media/source/' . ($index < 3 ? 'video.mkv' : 'track.flac')]];
            }
            $this->values[] = [
                'monotonic' => $at,
                'heartbeat' => ['namespace' => 'worker.baander.app', 'bootId' => 'boot', 'unit' => 'control', 'epoch' => 1, 'draining' => false, 'time' => $at - .1],
                'lease' => ['owner_boot_id' => 'boot', 'epoch' => 1, 'state' => 'active', 'unexpired' => true],
                'memory.events' => 'oom_kill ' . ($phase === 'memory' && $at > 5 ? 1 : 0) . "\nmax 0",
                'pids.events' => 'max ' . ($phase === 'pid' && $at > 5 ? 1 : 0),
                'pids.max' => '256',
                'memory.max' => (string) (4480 * 1024 * 1024),
                'memory.current' => '1234',
                'cpu.stat' => 'usage_usec ' . ($at * 2_000_000),
                'ordinaryReceiverDepth' => 2000,
                'nativeProcesses' => $native,
                'cgroup' => '/qualification/execution',
            ];
            $backlog[] = ['monotonic' => $at, 'receiverDepth' => 2000, 'acknowledged' => $at, 'relayedEvents' => $at];
        }
        $control = $this->values;
        foreach ($control as &$sample) {
            $sample['memory.events'] = "oom_kill 0\nmax 0";
        }
        unset($sample);
        $this->lines('control-samples.jsonl', $control);
        $this->lines('execution-samples.jsonl', $this->values);
        $media = [
            'videoJobs' => ['one', 'two', 'three'],
            'audioBytes' => 8192,
            'desiredStateSamples' => array_fill(0, 40, ['seconds' => .5, 'observedRevision' => 2, 'desiredRevision' => 2]),
            'pressureStartedAt' => 0,
            'pressureEndedAt' => 60,
            'ordinaryBacklog' => $backlog,
            'dueOccurrences' => array_fill(0, 4, ['execution' => ['deployment_boot_id' => 'boot', 'deployment_epoch' => 1, 'returned' => true]]),
        ];
        $this->json('media.json', ['evidence' => $media]);
        $this->lines('owned-failure.jsonl', [['ownedTerminal' => ['state' => 'exited', 'exitCode' => 137]]]);
        $this->lines(
            'pressure.log',
            $phase === 'memory' ? [
                ['oomTarget' => ['pid' => 20, 'startTicks' => '100']],
                ['oomTargetDied' => ['pid' => 20, 'startTicks' => '100']],
            ] : [['forkRefused' => true]],
        );
        return $media;
    }

    /** @return iterable<string,array{string}> */
    public static function capacityPhases(): iterable
    {
        yield 'cpu' => ['cpu'];
        yield 'memory' => ['memory'];
        yield 'pid' => ['pid'];
    }

    #[DataProvider('capacityPhases')]
    public function testCapacityPhasesRetainIndependentControlAndNativeEvidence(string $phase): void
    {
        $this->capacityFixture($phase);
        $report = ControlCapacityVerifier::verify($this->root, $phase);
        self::assertSame($phase, $report['phase']);
        self::assertSame(['control' => 'control', 'execution' => 'execution'], $report['containerIds']);
        if ($phase === 'cpu') {
            self::assertSame(61, $report['occupiedSlotSamples']);
            self::assertSame(130.0, $report['executionCpuSeconds']);
        } else {
            self::assertSame('execution-cgroup-counter', $report['exhaustion']['source']);
            self::assertTrue($report['controlSurvivedExecutionExhaustion']);
        }
    }

    /** @return iterable<string,array{string,string,bool}> */
    public static function containedSamplerOutcomes(): iterable
    {
        yield 'memory drain' => ['memory', 'complete', true];
        yield 'pid drain' => ['pid', 'complete', true];
        yield 'clean completion after drain' => ['memory', 'zero', true];
        yield 'bounded event fallback' => ['memory', 'fallback', true];
        yield 'pid pressure retired' => ['pid', 'pressure', true];
        yield 'pid pressure clean' => ['pid', 'pressure-zero', true];
        yield 'pid pressure missing refusal' => ['pid', 'pressure-no-refusal', false];
        yield 'pid pressure truncated' => ['pid', 'pressure-truncated', false];
        foreach (['early-control', 'early-zero', 'early-execution', 'changed-drain-boot', 'gap', 'truncated', 'malformed', 'wrong-container', 'wrong-id', 'running', 'missing-events', 'other-exit', 'timeout', 'cancelled', 'signal', 'fallback-truncated', 'fallback-no-terminal', 'pressure'] as $case) {
            yield $case => ['memory', $case, false];
        }
    }

    #[DataProvider('containedSamplerOutcomes')]
    public function testContainedSamplerTerminationRequiresCompleteDrainEvidence(string $phase, string $case, bool $success): void
    {
        $this->capacityFixture($phase);
        $control = Reports::samples($this->root . '/control-samples.jsonl');
        $control[array_key_last($control)]['heartbeat']['draining'] = !in_array($case, ['early-control', 'early-zero', 'fallback-no-terminal'], true);
        if (in_array($case, ['early-control', 'early-zero'], true)) {
            $control = array_slice($control, 0, 60);
        }
        if ($case === 'fallback-no-terminal') {
            unlink($this->root . '/control-drain-capture.json');
        }
        if ($case === 'changed-drain-boot') {
            $control[array_key_last($control)]['heartbeat']['bootId'] = 'foreign';
        }
        if ($case === 'gap') {
            $control = array_merge(array_slice($control, 0, 4), array_slice($control, 10));
        }
        $this->lines('control-samples.jsonl', $control);
        if ($case === 'early-execution') {
            $this->lines('execution-samples.jsonl', array_slice($this->values, 0, 5));
        }
        if (str_starts_with($case, 'fallback')) {
            foreach ($this->values as &$sample) {
                $sample['memory.events'] = 'oom_kill 0' . "\nmax " . ($sample['monotonic'] > 5 ? '1' : '0');
            }
            unset($sample);
            $this->lines('execution-samples.jsonl', $this->values);
            $this->lines('worker-oom-events-final.jsonl', [['Type' => 'container', 'Action' => 'oom', 'Actor' => ['ID' => 'execution']]]);
        }
        if (in_array($case, ['truncated', 'malformed', 'fallback-truncated'], true)) {
            file_put_contents($this->root . '/control-samples.jsonl', $case === 'malformed' ? "{broken}\n" : '{', FILE_APPEND);
        }
        if ($case === 'running') {
            $drain = Reports::json($this->root . '/drain.json');
            $drain[0]['State']['Running'] = true;
            $this->json('drain.json', $drain);
        }
        if ($case === 'missing-events') {
            unlink($this->root . '/worker-oom-events-capture.json');
        }
        if ($case === 'pressure-no-refusal') {
            $this->lines('pressure.log', [['holdFinished' => true]]);
        } elseif ($case === 'pressure-truncated') {
            file_put_contents($this->root . '/pressure.log', '{', FILE_APPEND);
        }
        $result = new \Baander\Tooling\Process\ProcessResult(
            $case === 'other-exit' ? 7 : (in_array($case, ['early-zero', 'zero', 'pressure-zero'], true) ? 0 : 137),
            $case === 'signal' ? 9 : null,
            $case === 'timeout',
            $case === 'cancelled',
            '',
            '',
            1,
        );
        $completion = new \Baander\Tooling\Fixtures\Worker\ContainedSamplerDrain($this->root, $phase, [
            'control' => ['name' => 'control-name', 'id' => $case === 'wrong-id' ? 'foreign' : 'control'],
            'execution' => ['name' => 'execution-name', 'id' => 'execution'],
        ]);
        if (!$success) {
            $this->expectException(RuntimeException::class);
        }
        if (str_starts_with($case, 'pressure')) {
            $completion->completePressure($result);
            self::addToAssertionCount(1);
            return;
        }
        $completion->complete($case === 'wrong-container' ? 'foreign' : 'control-name', 'control-samples.jsonl', $result);
        if ($success) {
            $completion->complete('execution-name', 'execution-samples.jsonl', $result);
            self::addToAssertionCount(1);
        }
    }

    public function testMeasurementsAcceptContainerTerminationOnlyAfterVerifiedContainedDrain(): void
    {
        $this->capacityFixture('memory');
        $control = Reports::samples($this->root . '/control-samples.jsonl');
        $control[array_key_last($control)]['heartbeat']['draining'] = true;
        $this->lines('source-control.jsonl', $control);
        $script = <<<'PHP_CHILD'
            <?php
            echo file_get_contents(__DIR__ . '/source-control.jsonl');
            exit(137);
            PHP_CHILD;
        file_put_contents($this->root . '/docker', '#!' . PHP_BINARY . "\n" . $script);
        chmod($this->root . '/docker', 0700);
        $runtime = new \Baander\Tooling\Fixtures\Runtime(dirname(__DIR__, 3), 5);
        $docker = new \Baander\Tooling\Docker\Client(
            $runtime->executor,
            new \Baander\Tooling\Docker\Target($this->root . '/docker', 'unix:///fixture.sock', 'fixture-daemon', null, null),
            [],
        );
        $measurements = new \Baander\Tooling\Fixtures\Worker\Measurements($runtime, $docker, $this->root);
        try {
            $process = $measurements->container(['exec', 'control-name', 'sampler'], 'control-samples.jsonl', 'errors.log', 3);
            $runtime->await(static fn(): bool => !$runtime->alive($process), 2, .01);
            $measurements->finish(new \Baander\Tooling\Fixtures\Worker\ContainedSamplerDrain($this->root, 'memory', [
                'control' => ['name' => 'control-name', 'id' => 'control'],
                'execution' => ['name' => 'execution-name', 'id' => 'execution'],
            ]));
            self::assertSame(66, count(Reports::samples($this->root . '/control-samples.jsonl')));
            self::assertFileDoesNotExist($this->root . '/qualification.json', 'Sampler completion must not publish before all producer outcomes are checked');
        } finally {
            $runtime->close();
        }
    }

    /** @return iterable<string,array{string,bool}> */
    public static function terminalHeartbeatOutcomes(): iterable
    {
        yield 'preserved final heartbeat' => ['complete', true];
        foreach (['missing-receipt', 'missing-frame', 'wrong-container', 'wrong-source', 'not-after-stop', 'wrong-namespace', 'wrong-boot', 'wrong-epoch', 'wrong-unit', 'not-draining', 'ready', 'stale', 'future', 'truncated'] as $case) {
            yield $case => [$case, false];
        }
    }

    #[DataProvider('terminalHeartbeatOutcomes')]
    public function testPersistedDrainHeartbeatProvesTransitionAfterFinalSamplerRow(string $case, bool $success): void
    {
        $this->capacityFixture('memory');
        $frame = Reports::json($this->root . '/control-drain-heartbeat.json');
        match ($case) {
            'wrong-namespace' => $frame['namespace'] = 'foreign.baander.app',
            'wrong-boot' => $frame['bootId'] = 'foreign',
            'wrong-epoch' => $frame['epoch'] = 2,
            'wrong-unit' => $frame['unit'] = 'execution',
            'not-draining' => $frame['draining'] = false,
            'ready' => $frame['ready'] = true,
            'stale' => $frame['time'] = 50,
            'future' => $frame['time'] = 68,
            default => null,
        };
        $this->json('control-drain-heartbeat.json', $frame);
        if ($case === 'missing-receipt') {
            unlink($this->root . '/control-drain-capture.json');
        } elseif ($case === 'missing-frame') {
            unlink($this->root . '/control-drain-heartbeat.json');
        } elseif ($case === 'truncated') {
            file_put_contents($this->root . '/control-drain-heartbeat.json', '{');
        } elseif (in_array($case, ['wrong-container', 'wrong-source', 'not-after-stop'], true)) {
            $receipt = Reports::json($this->root . '/control-drain-capture.json');
            if ($case === 'not-after-stop') {
                $receipt['copiedAfterStop'] = false;
            } else {
                $receipt[$case === 'wrong-container' ? 'containerId' : 'source'] = 'foreign';
            }
            $this->json('control-drain-capture.json', $receipt);
        }
        $completion = new \Baander\Tooling\Fixtures\Worker\ContainedSamplerDrain($this->root, 'memory', [
            'control' => ['name' => 'control-name', 'id' => 'control'],
            'execution' => ['name' => 'execution-name', 'id' => 'execution'],
        ]);
        if (!$success) {
            $this->expectException(RuntimeException::class);
        }
        $completion->complete('control-name', 'control-samples.jsonl', new \Baander\Tooling\Process\ProcessResult(137, null, false, false, '', '', 1));
        self::assertSame('control:/run/baander-worker/boot/control.json', $completion->terminalHeartbeatSource());
        $samples = Reports::samples($this->root . '/control-samples.jsonl');
        self::assertCount(66, $samples);
        self::assertFalse($samples[array_key_last($samples)]['heartbeat']['draining']);
        self::assertFileDoesNotExist($this->root . '/qualification.json');
    }

    /** @return iterable<string,array{string,bool}> */
    public static function memoryPressureOutcomes(): iterable
    {
        yield 'terminal native death' => ['complete', true];
        yield 'clean native death' => ['zero', true];
        yield 'clean early exit' => ['no-terminal-zero', false];
        foreach (['no-terminal', 'wrong-pid', 'wrong-start', 'wrong-argv', 'missing-argv', 'truncated', 'reversed', 'other-exit', 'timeout', 'cancelled', 'signal'] as $case) {
            yield $case => [$case, false];
        }
    }

    #[DataProvider('memoryPressureOutcomes')]
    public function testMemoryPressureKilledAfterTerminalEvidenceRetainsStrictCompletion(string $case, bool $success): void
    {
        $this->capacityFixture('memory');
        $control = Reports::samples($this->root . '/control-samples.jsonl');
        $control[array_key_last($control)]['heartbeat']['draining'] = true;
        $this->lines('control-samples.jsonl', $control);
        $layout = ApplicationLayout::load();
        $target = ['pid' => 20, 'startTicks' => '100', 'argv' => ['/usr/local/bin/php', $layout->path('bin/worker-native.php')]];
        if ($case === 'missing-argv') {
            unset($target['argv']);
        }
        $dead = $target;
        match ($case) {
            'wrong-pid' => $dead['pid'] = 21,
            'wrong-start' => $dead['startTicks'] = '101',
            'wrong-argv' => $dead['argv'] = ['/usr/local/bin/php', $layout->path('bin/console')],
            default => null,
        };
        $records = [['oomTarget' => $target], ['oomTargetDied' => $dead, 'monotonic' => 65]];
        if (in_array($case, ['no-terminal', 'no-terminal-zero'], true)) {
            array_pop($records);
        } elseif ($case === 'reversed') {
            $records = array_reverse($records);
        }
        $payload = implode('', array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR) . "\n", $records));
        if ($case === 'truncated') {
            $payload .= '{';
        }
        $runner = new EngineExecutor(ExecutionContext::bounded(5, getenv(), dirname(__DIR__, 3)));
        try {
            $result = $runner->run(new ProcessSpec(
                [PHP_BINARY, '-r', 'echo $argv[1]; exit((int)$argv[2]);', $payload, $case === 'other-exit' ? '7' : (in_array($case, ['zero', 'no-terminal-zero'], true) ? '0' : '137')],
                logPath: $this->root . '/pressure.log',
            ), new CompletionPolicy(null));
            if (in_array($case, ['timeout', 'cancelled', 'signal'], true)) {
                $result = new \Baander\Tooling\Process\ProcessResult(137, $case === 'signal' ? 9 : null, $case === 'timeout', $case === 'cancelled', '', '', 1);
            }
            $completion = new \Baander\Tooling\Fixtures\Worker\ContainedSamplerDrain($this->root, 'memory', [
                'control' => ['name' => 'control-name', 'id' => 'control'],
                'execution' => ['name' => 'execution-name', 'id' => 'execution'],
            ]);
            if (!$success) {
                $this->expectException(RuntimeException::class);
            }
            try {
                $completion->completePressure($result);
            } catch (RuntimeException $error) {
                if (!$success) {
                    $expectedStatus = $result->timedOut ? 124 : ($result->cancelled ? 143 : ($result->exitCode ?: 1));
                    $cleanup = new RuntimeException('Later cleanup failed', previous: $error);
                    self::assertSame($expectedStatus, \Baander\Tooling\Fixtures\Runtime::status($cleanup));
                    self::assertStringContainsString($error->getMessage(), \Baander\Tooling\Fixtures\Runtime::failure($cleanup));
                    self::assertFileDoesNotExist($this->root . '/qualification.json');
                }
                throw $error;
            }
            self::assertFileDoesNotExist($this->root . '/qualification.json');
        } finally {
            $runner->close();
        }
    }

    #[DataProvider('unexpectedContainedProbeExits')]
    public function testUnexpectedPressureExitPrecedesSamplerProofFailure(int $status): void
    {
        $this->capacityFixture('memory');
        $this->lines('pressure.log', []);
        $completion = new \Baander\Tooling\Fixtures\Worker\ContainedSamplerDrain($this->root, 'memory', [
            'control' => ['name' => 'control-name', 'id' => 'control'],
            'execution' => ['name' => 'execution-name', 'id' => 'execution'],
        ]);
        $runner = new EngineExecutor(ExecutionContext::bounded(5, getenv(), dirname(__DIR__, 3)));
        try {
            $pressure = $runner->run(new ProcessSpec([PHP_BINARY, '-r', 'exit((int)$argv[1]);', (string) $status]), new CompletionPolicy(null));
            $sampler = $runner->run(new ProcessSpec([PHP_BINARY, '-r', 'exit(137);']), new CompletionPolicy(null));
            try {
                $completion->checkOutcome($pressure);
                $completion->complete('control-name', 'control-samples.jsonl', $sampler);
                self::fail('Unexpected pressure exit was accepted');
            } catch (\Baander\Tooling\Process\CommandFailure $error) {
                self::assertSame($status, $error->getCode());
                self::assertSame($pressure, $error->result);
                self::assertFileDoesNotExist($this->root . '/qualification.json');
            }
        } finally {
            $runner->close();
        }
    }

    /** @return iterable<string,array{int}> */
    public static function unexpectedContainedProbeExits(): iterable
    {
        yield 'ordinary failure' => [7];
        yield 'PHP fatal failure' => [255];
    }

    /** @return iterable<string,array{string,int,bool}> */
    public static function ownedProbeOutcomes(): iterable
    {
        foreach (['native-error', 'terminal-reply', 'native-error-pid'] as $case) {
            yield $case . ' clean' => [$case, 0, true];
            yield $case . ' contained' => [$case, 137, true];
        }
        foreach (['missing', 'wrong-boot', 'wrong-phase', 'no-attempt', 'successful-reply', 'foreign-reply', 'truncated', 'timeout', 'cancelled', 'signal'] as $case) {
            yield $case => [$case, 137, false];
        }
        yield 'unexpected exit' => ['native-error', 7, false];
    }

    #[DataProvider('ownedProbeOutcomes')]
    public function testOwnedProbeCompletionRequiresTerminalCurrentAuthority(string $case, int $status, bool $success): void
    {
        $phase = $case === 'native-error-pid' ? 'pid' : 'memory';
        $this->capacityFixture($phase);
        $identity = ['namespace' => 'worker.baander.app', 'bootId' => 'boot', 'epoch' => 1];
        $control = Reports::samples($this->root . '/control-samples.jsonl');
        foreach ($control as &$sample) {
            $sample['heartbeat']['namespace'] = $identity['namespace'];
        }
        unset($sample);
        $control[array_key_last($control)]['heartbeat']['draining'] = true;
        $this->lines('control-samples.jsonl', $control);
        $terminal = ['phase' => $phase, 'identity' => $identity, 'attempts' => 2, 'nativeFailure' => 'Native execution reply read failed.'];
        if (in_array($case, ['terminal-reply', 'successful-reply', 'foreign-reply'], true)) {
            unset($terminal['nativeFailure']);
            $terminal['ownedTerminal'] = $identity + ['state' => 'exited', 'exitCode' => $case === 'successful-reply' ? 0 : 137, 'attempt' => 2];
            if ($case === 'foreign-reply') {
                $terminal['ownedTerminal']['bootId'] = 'foreign';
            }
        } elseif ($case === 'wrong-boot') {
            $terminal['identity']['bootId'] = 'foreign';
        } elseif ($case === 'wrong-phase') {
            $terminal['phase'] = 'pid';
        } elseif ($case === 'no-attempt') {
            $terminal['attempts'] = 0;
        }
        $this->lines('owned-failure.jsonl', $case === 'missing' ? [] : [$terminal]);
        if ($case === 'truncated') {
            file_put_contents($this->root . '/owned-failure.jsonl', '{', FILE_APPEND);
        }
        $result = new \Baander\Tooling\Process\ProcessResult($status, $case === 'signal' ? 9 : null, $case === 'timeout', $case === 'cancelled', '', '', 1);
        $completion = new \Baander\Tooling\Fixtures\Worker\ContainedSamplerDrain($this->root, $phase, [
            'control' => ['name' => 'control-name', 'id' => 'control'],
            'execution' => ['name' => 'execution-name', 'id' => 'execution'],
        ]);
        if (!$success) {
            $this->expectException(RuntimeException::class);
        }
        $completion->completeOwnedFailure($result);
        self::assertFileDoesNotExist($this->root . '/qualification.json');
    }

    /** @return iterable<string,array{string}> */
    public static function invalidCapacity(): iterable
    {
        foreach ([
            'changed-event-identity',
            'interrupted-monitor',
            'control-oom',
            'wrong-budget',
            'overlapping-cpus',
            'changed-boot',
            'expired-lease',
            'drain-timeout',
            'changed-container',
            'restarted',
            'no-exhaustion',
            'missing-owned-failure',
            'requested-drain',
            'still-running',
            'wrong-oom-target',
        ] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidCapacity')]
    public function testCapacityNegativeControlsFail(string $case): void
    {
        $this->capacityFixture('memory');
        $before = Reports::json($this->root . '/containers-before.json');
        $capture = Reports::json($this->root . '/worker-oom-events-capture.json');
        $final = Reports::json($this->root . '/drain.json');
        switch ($case) {
            case 'changed-event-identity': $capture['containerIds']['control'] = 'foreign';
                break;
            case 'interrupted-monitor': $capture['monitorAliveAfterDrainAndCapture'] = false;
                break;
            case 'control-oom': $this->lines(
                'worker-oom-events-final.jsonl',
                [['Type' => 'container', 'Action' => 'oom', 'Actor' => ['ID' => 'control']]],
            );
                break;
            case 'wrong-budget': $before[0]['HostConfig']['PidsLimit'] = 128;
                break;
            case 'overlapping-cpus': $before[1]['HostConfig']['CpusetCpus'] = '1';
                break;
            case 'changed-boot':
            case 'expired-lease':
                $control = Reports::samples($this->root . '/control-samples.jsonl');
                if ($case === 'changed-boot') {
                    $control[10]['heartbeat']['bootId'] = 'foreign';
                } else {
                    $control[10]['lease']['unexpired'] = false;
                }
                $this->lines('control-samples.jsonl', $control);
                break;
            case 'drain-timeout': file_put_contents($this->root . '/drain-seconds.txt', '36');
                break;
            case 'changed-container': $final[0]['Id'] = 'foreign';
                break;
            case 'restarted': $final[1]['RestartCount'] = 1;
                break;
            case 'no-exhaustion':
                foreach ($this->values as &$sample) {
                    $sample['memory.events'] = "oom_kill 0\nmax 0";
                }
                unset($sample);
                $this->lines('execution-samples.jsonl', $this->values);
                break;
            case 'missing-owned-failure': $this->lines('owned-failure.jsonl', []);
                break;
            case 'requested-drain': file_put_contents($this->root . '/drain-trigger.txt', 'requested-graceful-shutdown');
                break;
            case 'still-running': $final[0]['State']['Running'] = true;
                break;
            case 'wrong-oom-target': $this->lines('pressure.log', [['oomTarget' => ['pid' => 20]], ['oomTargetDied' => ['pid' => 21]]]);
                break;
        }
        $this->json('containers-before.json', $before);
        $this->json('worker-oom-events-capture.json', $capture);
        $this->json('drain.json', $final);
        $this->expectException(RuntimeException::class);
        ControlCapacityVerifier::verify($this->root, 'memory');
    }

    public function testPidExhaustionFallbackRequiresMatchingContemporaneousKernelCounter(): void
    {
        $this->capacityFixture('cpu');
        $kernel = [
            'cgroup' => '/qualification/execution',
            'pids.max' => '256',
            'pids.events' => 'max 1',
            'monotonic' => 1,
            'pids.current' => '256',
        ];
        $this->lines('pressure.log', [['forkRefused' => true, 'executionCgroup' => $kernel]]);
        self::assertSame(
            'execution-pressure-process-kernel-counter',
            ControlCapacityVerifier::exhausted($this->root, 'pid')['source'],
        );
        $kernel['cgroup'] = '/foreign';
        $this->lines('pressure.log', [['forkRefused' => true, 'executionCgroup' => $kernel]]);
        $this->expectException(RuntimeException::class);
        ControlCapacityVerifier::exhausted($this->root, 'pid');
    }

    public function testMemoryOomFallbackRequiresExactContainerAndCeilingFailures(): void
    {
        $this->capacityFixture('cpu');
        $this->values[10]['memory.events'] = "oom_kill 0\nmax 1";
        $this->lines('execution-samples.jsonl', $this->values);
        $this->lines(
            'worker-oom-events.jsonl',
            [['Type' => 'container', 'Action' => 'oom', 'Actor' => ['ID' => 'execution']]],
        );
        self::assertSame(
            'exact-container-docker-oom-event',
            ControlCapacityVerifier::exhausted($this->root, 'memory')['source'],
        );
        $this->lines(
            'worker-oom-events.jsonl',
            [['Type' => 'container', 'Action' => 'oom', 'Actor' => ['ID' => 'foreign']]],
        );
        $this->expectException(RuntimeException::class);
        ControlCapacityVerifier::exhausted($this->root, 'memory');
    }

    private function mediaFixture(): void
    {
        $media = $this->capacityFixture();
        $media += [
            'seekHandlingSeconds' => .2,
            'cancelHandlingSeconds' => .3,
            'seekReadinessSeconds' => 2,
            'cancelRetirementSeconds' => 2,
            'attachments' => [['phase' => 'running', 'references' => 1], ['phase' => 'paused', 'references' => 1]],
            'independentOwners' => 4,
            'unaffectedOwnerJobs' => ['two', 'three'],
            'generations' => [1, 2],
            'retiredOutputStatuses' => ['one' => 410],
            'retainedOutputFiles' => 2,
            'socketReconnectReplay' => true,
            'pendingCancellation' => ['outcome' => 'cancelled', 'nativeLaunches' => 0, 'references' => 0],
            'seekStartedAt' => 20,
            'seekReadyAt' => 22,
            'cancelStartedAt' => 40,
            'cancelRetiredAt' => 42,
        ];
        $this->json('media.json', ['evidence' => $media]);
        foreach ($this->values as &$sample) {
            if ($sample['monotonic'] >= 42) {
                array_shift($sample['nativeProcesses']);
            }
        }
        unset($sample);
        $this->lines('execution-samples.jsonl', $this->values);
        $this->lines('backlog.jsonl', $media['ordinaryBacklog']);
        $this->lines(
            'native-launches.jsonl',
            [
                ['jobId' => 'one', 'generation' => 1, 'predecessors' => []],
                ['jobId' => 'one', 'generation' => 2, 'predecessors' => []],
            ],
        );
    }

    public function testMediaControlsProveSeekCancelOccupancyAndUniqueNativeLaunches(): void
    {
        $this->mediaFixture();
        $report = MediaControlVerifier::verify($this->root);
        self::assertSame(2, $report['nativeLaunches']);
        self::assertSame(38, $report['fullOccupancySamples']);
        self::assertSame(19, $report['postCancellationSamples']);
        self::assertSame(60, $report['ordinaryBacklog']['acknowledgedProgress']);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidMedia(): iterable
    {
        foreach ([
            'changed-container',
            'restarted',
            'unacknowledged',
            'slow-control',
            'pending-launched',
            'same-generation',
            'retired-available',
            'missing-replay',
            'wrong-owner-count',
            'wrong-references',
            'missing-audio',
            'missing-video',
            'low-cpu',
            'changed-control-boot',
            'stalled-backlog',
            'duplicate-launch',
            'overlapping-launch',
            'malformed-interior',
        ] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('invalidMedia')]
    public function testMediaNegativeControlsFail(string $case): void
    {
        $this->mediaFixture();
        $media = Reports::json($this->root . '/media.json');
        $after = Reports::json($this->root . '/containers-after.json');
        switch ($case) {
            case 'changed-container': $after[0]['Id'] = 'foreign';
                break;
            case 'restarted': $after[1]['RestartCount'] = 1;
                break;
            case 'unacknowledged': $media['evidence']['desiredStateSamples'][0]['observedRevision'] = 0;
                break;
            case 'slow-control': $media['evidence']['desiredStateSamples'][0]['seconds'] = 3;
                break;
            case 'pending-launched': $media['evidence']['pendingCancellation']['nativeLaunches'] = 1;
                break;
            case 'same-generation': $media['evidence']['generations'] = [1, 1];
                break;
            case 'retired-available': $media['evidence']['retiredOutputStatuses']['one'] = 200;
                break;
            case 'missing-replay': $media['evidence']['socketReconnectReplay'] = false;
                break;
            case 'wrong-owner-count': $media['evidence']['independentOwners'] = 3;
                break;
            case 'wrong-references': $media['evidence']['attachments'][0]['references'] = 2;
                break;
            case 'missing-audio': array_pop($this->values[10]['nativeProcesses']);
                break;
            case 'missing-video': array_shift($this->values[10]['nativeProcesses']);
                break;
            case 'low-cpu':
                foreach ($this->values as &$sample) {
                    $sample['cpu.stat'] = 'usage_usec 10';
                }
                unset($sample);
                break;
            case 'changed-control-boot':
                $control = Reports::samples($this->root . '/control-samples.jsonl');
                $control[10]['heartbeat']['bootId'] = 'foreign';
                $this->lines('control-samples.jsonl', $control);
                break;
            case 'stalled-backlog':
                $backlog = Reports::samples($this->root . '/backlog.jsonl');
                foreach ($backlog as &$row) {
                    $row['acknowledged'] = 0;
                }
                unset($row);
                $this->lines('backlog.jsonl', $backlog);
                break;
            case 'duplicate-launch': $this->lines(
                'native-launches.jsonl',
                array_fill(0, 2, ['jobId' => 'one', 'generation' => 1, 'predecessors' => []]),
            );
                break;
            case 'overlapping-launch': $this->lines('native-launches.jsonl', [['jobId' => 'one', 'generation' => 1, 'predecessors' => [20]]]);
                break;
            case 'malformed-interior': file_put_contents($this->root . '/backlog.jsonl', "{\n{}\n");
                break;
        }
        $this->json('media.json', $media);
        $this->json('containers-after.json', $after);
        $this->lines('execution-samples.jsonl', $this->values);
        $this->expectException(RuntimeException::class);
        MediaControlVerifier::verify($this->root);
    }

    public function testThresholdCliAndMeasuredComparisonPreserveContracts(): void
    {
        $runner = new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)));
        $entrypoint = dirname(__DIR__, 3) . '/tests/Fixtures/Worker/autoscaling-verify.php';
        $thresholds = $runner->run(new ProcessSpec([PHP_BINARY, $entrypoint, 'thresholds']));
        self::assertSame(AutoscalingVerifier::THRESHOLDS, json_decode($thresholds->stdout, true, flags: JSON_THROW_ON_ERROR));
        foreach ([1 => 100, 2 => 80, 4 => 60] as $maximum => $seconds) {
            mkdir($this->root . '/' . $maximum, 0700);
            $this->json($maximum . '/qualification.json', ['bursts' => [['seconds' => $seconds], ['seconds' => $seconds]]]);
        }
        AutoscalingVerifier::compare($this->root);
        $comparison = Reports::json($this->root . '/comparison.json');
        self::assertSame([1.25, 1.25], $comparison['speedups'][2]);
        $this->json('4/qualification.json', ['bursts' => [['seconds' => 100], ['seconds' => 100]]]);
        $this->expectException(RuntimeException::class);
        AutoscalingVerifier::compare($this->root);
    }

    public function testVerifierCommandLinesPublishReportsAndRejectUnknownModes(): void
    {
        $runner = new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)));
        $directory = dirname(__DIR__, 3) . '/tests/Fixtures/Worker/';
        $this->autoscalingFixture();
        $autoscaling = $runner->run(new ProcessSpec([PHP_BINARY, $directory . 'autoscaling-verify.php', 'verify', $this->root, '2']));
        self::assertSame(400, json_decode($autoscaling->stdout, true, flags: JSON_THROW_ON_ERROR)['samples']);
        $this->capacityFixture('memory');
        $capacity = $runner->run(new ProcessSpec([PHP_BINARY, $directory . 'control-capacity-verify.php', 'verify', $this->root, 'memory']));
        self::assertTrue(json_decode($capacity->stdout, true, flags: JSON_THROW_ON_ERROR)['controlSurvivedExecutionExhaustion']);
        self::assertSame('memory', Reports::json($this->root . '/qualification.json')['phase']);
        $this->mediaFixture();
        $media = $runner->run(new ProcessSpec([PHP_BINARY, $directory . 'media-control-verify.php', $this->root]));
        self::assertSame(2, json_decode($media->stdout, true, flags: JSON_THROW_ON_ERROR)['nativeLaunches']);
        $invalid = $runner->run(new ProcessSpec([PHP_BINARY, $directory . 'autoscaling-verify.php', 'unknown', $this->root]), new CompletionPolicy(null));
        self::assertSame(1, $invalid->exitCode);
        self::assertStringContainsString('Unknown autoscaling mode', $invalid->stderr);
    }
}
