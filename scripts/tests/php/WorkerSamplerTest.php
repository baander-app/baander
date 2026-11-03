<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{EngineExecutor, ExecutionContext};
use Baander\Tooling\Development\Environment;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Process\{CommandExecutor, CompletionPolicy, ProcessSpec, RunningProcess};
use Baander\Tooling\Fixtures\Worker\{PostgresSampler, CompletionCursor, EvidenceMode, AutoscalingStatusObservation, KillOrderEvidence};
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerSamplerTest extends TestCase
{
    public function testStatusObservationRetriesOnlyTransientAdmissionContentionWithFreshTime(): void
    {
        $times = [];
        $expected = ['state' => 'known', 'desired' => 2, 'resources' => ['state' => 'unknown']];
        $observation = AutoscalingStatusObservation::capture(static function (float $now) use (&$times, $expected): array {
            $times[] = $now;
            if (count($times) < 3) {
                throw new RuntimeException('Admission lock deadline exceeded.');
            }
            return $expected;
        });
        self::assertSame($expected, $observation['status']);
        self::assertSame(3, $observation['attempts']);
        self::assertGreaterThan($times[0], $times[1]);
        self::assertGreaterThan($times[1], $times[2]);
    }

    public function testStatusObservationPreservesImmediateSuccess(): void
    {
        $status = ['state' => 'known', 'desired' => 1];
        self::assertSame(['status' => $status, 'attempts' => 1], AutoscalingStatusObservation::capture(static fn(float $now): array => $status));
    }

    public function testPersistentAdmissionContentionStillFailsWithinOneSampleInterval(): void
    {
        $started = hrtime(true);
        $attempts = 0;
        try {
            AutoscalingStatusObservation::capture(static function (float $now) use (&$attempts): array {
                ++$attempts;
                usleep(50_000);
                throw new RuntimeException('Admission lock deadline exceeded.');
            });
            self::fail('Persistent contention produced an observation');
        } catch (RuntimeException $error) {
            self::assertSame('Admission lock deadline exceeded.', $error->getMessage());
            self::assertGreaterThan(1, $attempts);
            self::assertLessThan(.5, (hrtime(true) - $started) / 1e9);
        }
    }

    public function testOtherStatusErrorsPropagateWithoutRetry(): void
    {
        $attempts = 0;
        $original = new RuntimeException('Admission lock unavailable.');
        try {
            AutoscalingStatusObservation::capture(static function (float $now) use (&$attempts, $original): array {
                ++$attempts;
                throw $original;
            });
            self::fail('Unexpected status failure was swallowed');
        } catch (RuntimeException $error) {
            self::assertSame($original, $error);
            self::assertSame(1, $attempts);
        }
    }

    /** @return array<string,string> */
    private function records(int $pid = 42, string $ticks = '1234', int $pss = 10): array
    {
        return [
            '/sys/fs/cgroup/memory.current' => "100\n",
            '/sys/fs/cgroup/memory.events' => "oom_kill 0\n",
            '/proc/' . $pid . '/status' => "Name:\tpostgres\nState:\tS (sleeping)\nPPid:\t1\nVmRSS:\t20 kB\n",
            '/proc/' . $pid . '/stat' => $pid . ' (postgres worker) S ' . implode(' ', array_fill(0, 18, '0')) . ' ' . $ticks . ' 0',
            '/proc/' . $pid . '/cmdline' => "postgres\0-D\0/var/lib/postgresql\0",
            '/proc/' . $pid . '/smaps_rollup' => "Rss: 20 kB\nPss: " . $pss . " kB\nPrivate_Dirty: 3 kB\n",
        ];
    }

    public function testExitedPostgresIdentitiesDoNotAccumulateAcrossSamples(): void
    {
        $sampler = new PostgresSampler(new Client(
            new WorkerSamplerRecordingRunner(),
            new Target('/usr/bin/docker', 'unix:///fixture.sock', 'fixture-daemon', null, null),
            [],
        ), 'fixture', 'disposable_db');
        $before = memory_get_usage();
        for ($pid = 1; $pid < 20_000; ++$pid) {
            $sampler->sample($this->records($pid), (float) $pid);
        }
        self::assertLessThan(1_048_576, memory_get_usage() - $before);
    }

    public function testCompletionCursorRetainsPartialAppendAndReleasesHistoricalBatches(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-completions-');
        try {
            $cursor = new CompletionCursor($path);
            file_put_contents($path, "{\"sequence\":0}\n{\"sequence\":");
            self::assertSame("{\"sequence\":0}\n", $cursor->read(EvidenceMode::Live));
            self::assertSame('', $cursor->read(EvidenceMode::Live));
            file_put_contents($path, "1}\n", FILE_APPEND);
            self::assertSame("{\"sequence\":1}\n", $cursor->read(EvidenceMode::Completed));
            for ($sequence = 2; $sequence < 300; ++$sequence) {
                $line = json_encode(['sequence' => $sequence, 'payload' => str_repeat('x', 512)], JSON_THROW_ON_ERROR) . "\n";
                file_put_contents($path, $line, FILE_APPEND);
                self::assertSame($line, $cursor->read(EvidenceMode::Live));
            }
            self::assertGreaterThan(65_536, filesize($path));
            file_put_contents($path, '{', FILE_APPEND);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Incomplete final sample');
            $cursor->read(EvidenceMode::Completed);
        } finally {
            unlink($path);
        }
    }

    public function testRawNamespaceDecoderPreservesNulCmdlineAndMissingMetrics(): void
    {
        $raw = $this->records();
        $encoded = '';
        foreach ($raw as $path => $value) {
            $encoded .= $path . "\n" . chunk_split(base64_encode($value), 76, "\n") . "\n.\n";
        }
        self::assertSame($raw, PostgresSampler::decode($encoded));
        $sampler = new PostgresSampler(
            new Client(new WorkerSamplerRecordingRunner(), new Target('/usr/bin/docker', 'unix:///fixture.sock', 'fixture-daemon', 'fixture', null), []),
            'fixture-id',
            'worker_operator_test',
        );
        $sample = $sampler->sample($raw, 10.5);
        self::assertSame(10.5, $sample['monotonic']);
        self::assertSame("100\n", $sample['cgroup']['memory.current']);
        self::assertNull($sample['cgroup']['memory.peak']);
        self::assertSame(42, $sample['processes'][0]['pid']);
        self::assertSame('1234', $sample['processes'][0]['startTicks']);
        self::assertSame('postgres -D /var/lib/postgresql', $sample['processes'][0]['cmdline']);
        self::assertSame('10 kB', $sample['processes'][0]['smapsRollup']['Pss']);
        self::assertNull($sample['processes'][0]['status']['RssAnon']);
    }

    public function testMemoryContextRequestChoosesLargestGrowthAndOnlyRequestsOnce(): void
    {
        $runner = new WorkerSamplerRecordingRunner();
        $sampler = new PostgresSampler(
            new Client($runner, new Target('/usr/bin/docker', 'tcp://docker.baander.app:2376', 'fixture-daemon', null, null), []),
            'immutable-container',
            'disposable_db',
        );
        $first = array_replace($this->records(42, '1', 100), $this->records(43, '2', 200));
        $sampler->sample($first, 1);
        $next = array_replace(
            $this->records(42, '1', 190),
            $this->records(43, '2', 210),
            ['/sys/fs/cgroup/memory.current' => (string) (1536 * 1024 * 1024)],
        );
        $result = $sampler->sample($next, 2);
        self::assertSame(42, $result['memoryContextRequest']['pid']);
        self::assertSame(0, $result['memoryContextRequest']['exitCode']);
        self::assertSame('t', $result['memoryContextRequest']['stdout']);
        self::assertSame(
            [
                '/usr/bin/docker',
                '--host',
                'tcp://docker.baander.app:2376',
                'exec',
                '--user',
                'postgres',
                'immutable-container',
                'psql',
                '-X',
                '-w',
                '-U',
                'baander',
                '-d',
                'disposable_db',
                '-At',
                '-c',
                'SELECT pg_log_backend_memory_contexts(42)',
            ],
            $runner->calls[0],
        );
        $sampler->sample($next, 3);
        self::assertCount(1, $runner->calls);
    }

    public function testReusedPidDoesNotInheritPreviousProcessMemoryGrowth(): void
    {
        $runner = new WorkerSamplerRecordingRunner();
        $sampler = new PostgresSampler(
            new Client($runner, new Target('/usr/bin/docker', 'unix:///fixture.sock', 'fixture-daemon', 'fixture', null), []),
            'fixture',
            'disposable_db',
        );
        $sampler->sample(array_replace($this->records(42, '1', 10), $this->records(43, '2', 100)), 1);
        $next = array_replace(
            $this->records(42, '99', 1000),
            $this->records(43, '2', 120),
            ['/sys/fs/cgroup/memory.current' => (string) (1536 * 1024 * 1024)],
        );
        self::assertSame(43, $sampler->sample($next, 2)['memoryContextRequest']['pid']);
    }

    public function testDisappearedAndNonPostgresProcessesAreIgnored(): void
    {
        $sampler = new PostgresSampler(
            new Client(new WorkerSamplerRecordingRunner(), new Target('/usr/bin/docker', 'unix:///fixture.sock', 'fixture-daemon', 'fixture', null), []),
            'fixture',
            'disposable_db',
        );
        $raw = $this->records();
        unset($raw['/proc/42/stat']);
        self::assertSame([], $sampler->sample($raw, 1)['processes']);
        $raw = $this->records();
        $raw['/proc/42/status'] = "Name: sh\n";
        self::assertSame([], $sampler->sample($raw, 2)['processes']);
    }

    public function testMalformedNamespaceRecordsAreRejected(): void
    {
        foreach (["not-a-path\nAAAA\n.\n", "/proc/42/status\n*invalid*\n.\n"] as $raw) {
            try {
                PostgresSampler::decode($raw);
                self::fail('Accepted malformed namespace record');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Malformed', $error->getMessage());
            }
        }
    }

    public function testEmptyRawNamespaceCannotPublishReadiness(): void
    {
        $this->expectExceptionMessage('no postgres process identity');
        PostgresSampler::validateReady(['processes' => [], 'cgroup' => []]);
    }

    public function testSamplerUsesImmutableDatabaseNamespaceAndDaemonClockAndRejectsEmptyCollector(): void
    {
        $directory = sys_get_temp_dir() . '/baander-postgres-sampler-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        copy(__DIR__ . '/Fixtures/worker-sampler-docker.php', $directory . '/bin/docker');
        chmod($directory . '/bin/docker', 0755);
        $runner = new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)));
        $environment = array_replace(
            Environment::clean(),
            [
                'PATH' => $directory . '/bin:' . getenv('PATH'),
                'WORKER_SAMPLER_FAKE_ROOT' => $directory,
                'BAANDER_TEST_OPERATOR_IN_CONTAINER' => '1',
            ],
        );
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 3) . '/tests/Fixtures/Worker/control-capacity-postgres-sample.php',
            'database-postgres',
            'clock-web',
            '.01',
            'worker_operator_test',
            $directory . '/ready.json',
        ];
        try {
            $result = $runner->run(new ProcessSpec($command, environment: $environment));
            $sample = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(9876.5, $sample['monotonic']);
            self::assertSame("100\n", $sample['cgroup']['memory.current']);
            self::assertSame(42, $sample['processes'][0]['pid']);
            $ready = json_decode(file_get_contents($directory . '/ready.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(str_repeat('a', 64), $ready['containerId']);
            self::assertSame(str_repeat('b', 64), $ready['clockContainerId']);
            self::assertSame('docker-daemon-php-monotonic', $ready['clockSource']);
            $commands = file($directory . '/commands.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
                |> (static fn(array $lines): array => array_map(
                    static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                    $lines,
                ));
            $executions = [];
            foreach ($commands as $arguments) {
                if (in_array($arguments[0] ?? '', ['--host', '--context'], true)) {
                    $arguments = array_slice($arguments, 2);
                }
                if (($arguments[0] ?? '') === 'exec') {
                    $executions[] = $arguments;
                }
            }
            self::assertCount(2, $executions);
            $collectors = $executions
                |> (static fn(array $commands): array => array_filter(
                    $commands,
                    static fn(array $arguments): bool => ($arguments[1] ?? '') === '--user',
                ))
                |> array_values(...);
            self::assertCount(1, $collectors);
            self::assertSame(
                ['exec', '--user', 'postgres', str_repeat('a', 64), 'sh', '-ec'],
                array_slice($collectors[0], 0, 6),
            );
            self::assertCount(7, $collectors[0]);
            self::assertStringContainsString('/proc/[0-9]*', $collectors[0][6]);
            self::assertStringContainsString('smaps_rollup', $collectors[0][6]);
            self::assertContains(
                ['exec', str_repeat('b', 64), 'php', '-r', 'echo hrtime(true) / 1e9;'],
                $executions,
            );
            unlink($directory . '/ready.json');
            $failed = $runner->run(new ProcessSpec($command, environment: array_replace($environment, ['WORKER_SAMPLER_EMPTY' => '1'])), new CompletionPolicy(null));
            self::assertSame(1, $failed->exitCode);
            self::assertStringContainsString('no postgres process identity', $failed->stderr);
            self::assertFileDoesNotExist($directory . '/ready.json');
        } finally {
            $runner->run(new ProcessSpec(['rm', '-rf', '--', $directory]));
        }
    }

    public function testKillOrderSampleReadsRankedRolesAndSkipsLauncherSteps(): void
    {
        $proc = sys_get_temp_dir() . '/baander-kill-order-' . bin2hex(random_bytes(6));
        $process = static function (int $pid, array $arguments, int $nice, int $score) use ($proc): void {
            mkdir($proc . '/' . $pid, 0700, true);
            file_put_contents($proc . '/' . $pid . '/cmdline', $arguments === [] ? '' : implode("\0", $arguments) . "\0");
            // Fields after the command name: state first, niceness 17th and start time 20th.
            $fields = array_fill(0, 40, '0');
            $fields[0] = 'S';
            $fields[16] = (string) $nice;
            $fields[19] = (string) (1000 + $pid);
            file_put_contents($proc . '/' . $pid . '/stat', $pid . ' (php worker) ' . implode(' ', $fields));
            file_put_contents($proc . '/' . $pid . '/oom_score_adj', $score . "\n");
        };
        try {
            $process(1, ['/usr/local/bin/php', '/opt/baander/bin/console', 'app:worker', '--unit=execution'], 0, 300);
            $process(2, ['/usr/local/bin/php', '/opt/baander/bin/console', 'app:worker'], 0, 300);
            $process(3, [], 0, 0);
            $process(20, ['/usr/local/bin/php', '-d', 'memory_limit=64M', '/opt/baander/bin/worker-native.php'], 0, 300);
            $process(30, ['/usr/local/bin/php', '/opt/baander/src/Transcode/Infrastructure/Worker/../../../../bin/worker-media-job.php', 'video'], 0, 600);
            $process(40, ['/usr/lib/baander-fixture/ffmpeg', '-i', '/media/source/video.mkv'], 10, 600);
            $process(41, ['/usr/local/bin/php', '/usr/bin/ffmpeg', '-i', '/media/source/track.flac'], 10, 600);
            $process(42, ['/usr/bin/choom', '-n', '600', '--', 'ffmpeg', '-i', '/media/source/video.mkv'], 10, 300);
            $process(43, ['php', 'tests/Fixtures/Worker/control-capacity-sample.php', '240', 'execution'], 0, 300);
            // The sampler runs as a standalone script inside a worker unit, so it is loaded the same way here.
            $code = 'require $argv[1]; echo json_encode(KillOrderSample::processes($argv[2]), JSON_THROW_ON_ERROR);';
            $sampled = (new EngineExecutor(ExecutionContext::bounded(30, getenv(), dirname(__DIR__, 3))))->run(new ProcessSpec(
                [PHP_BINARY, '-r', $code, dirname(__DIR__, 3) . '/tests/Fixtures/Worker/KillOrderSample.php', $proc],
            ));
            $records = json_decode($sampled->stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($records);
            usort($records, static fn(array $left, array $right): int => $left['pid'] <=> $right['pid']);
            self::assertSame(
                [[1, 'supervisor', 0, 300], [20, 'native', 0, 300], [30, 'media-child', 0, 600], [40, 'native-command', 10, 600], [41, 'native-command', 10, 600]],
                array_map(static fn(array $record): array => [$record['pid'], $record['role'], $record['nice'], $record['oomScoreAdj']], $records),
            );
            self::assertSame('1040', $records[3]['startTicks']);
        } finally {
            (new EngineExecutor(ExecutionContext::bounded(30, getenv(), dirname(__DIR__, 3))))->run(new ProcessSpec(['rm', '-rf', '--', $proc]));
        }
    }

    public function testKillOrderEvidenceMatchesTheApplicationAndTheStandInEncoders(): void
    {
        self::assertSame(\App\Shared\Infrastructure\Worker\OutOfMemoryKillOrder::CONTROL_UNIT, KillOrderEvidence::CONTROL_UNIT);
        self::assertSame(\App\Shared\Infrastructure\Worker\OutOfMemoryKillOrder::EXECUTION_UNIT, KillOrderEvidence::EXECUTION_UNIT);
        self::assertSame(\App\Shared\Infrastructure\Worker\OutOfMemoryKillOrder::MEDIA_WORK, KillOrderEvidence::MEDIA_WORK);
        self::assertSame(\App\Shared\Infrastructure\Worker\NativeExecutionDaemon::COMMAND_NICENESS, KillOrderEvidence::NATIVE_COMMAND_NICENESS);
        $launcher = \App\Shared\Infrastructure\Worker\NativeExecutionDaemon::launcher();
        self::assertSame(['/usr/bin/setsid', '/usr/bin/nice', '-n', '10', '/usr/bin/choom', '-n', '600', '--'], $launcher);
        $fixtures = dirname(__DIR__, 3) . '/tests/Fixtures/Worker/';
        $replay = (string) file_get_contents($fixtures . 'media-cost-replay.php');
        self::assertStringContainsString("['/usr/bin/nice', '-n', '10', '/usr/bin/choom', '-n', '600', '--', '/usr/bin/ffmpeg'", $replay);
        $pressure = (string) file_get_contents($fixtures . 'control-capacity-pressure.php');
        self::assertStringContainsString("pcntl_setpriority(10)", $pressure);
        self::assertStringContainsString("file_put_contents('/proc/self/oom_score_adj', '600')", $pressure);
        self::assertSame(KillOrderEvidence::CPU_PRESSURE_PROCESSES, preg_match('/range\(1, ([0-9]+)\)/', $pressure, $match) === 1 ? (int) $match[1] : 0);
    }
}

final class WorkerSamplerRecordingRunner implements CommandExecutor
{
    /** @var list<non-empty-list<string>> */
    public array $calls = [];
    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        $this->calls[] = $command->argv;
        return new ProcessResult(0, null, false, false, 't', '', .01);
    }
    public function start(ProcessSpec $command): RunningProcess
    {
        throw new \LogicException('Unexpected start');
    }
    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        throw new \LogicException('Unexpected wait');
    }
    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        throw new \LogicException('Unexpected stop');
    }
}
