<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{EngineExecutor, ExecutionContext};
use Baander\Tooling\Development\Environment;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Process\{CommandExecutor, CompletionPolicy, ProcessSpec, RunningProcess};
use Baander\Tooling\Fixtures\Worker\{PostgresSampler, CompletionCursor, EvidenceMode};
use Baander\Tooling\Process\ProcessResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerSamplerTest extends TestCase
{
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
