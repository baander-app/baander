<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\{Docker, Environment, Runner};
use Baander\Tooling\Fixtures\Worker\PostgresSampler;
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

    public function testRawNamespaceDecoderPreservesNulCmdlineAndMissingMetrics(): void
    {
        $raw = $this->records();
        $encoded = '';
        foreach ($raw as $path => $value) {
            $encoded .= $path . "\n" . chunk_split(base64_encode($value), 76, "\n") . "\n.\n";
        }
        self::assertSame($raw, PostgresSampler::decode($encoded));
        $sampler = new PostgresSampler(
            new Docker(new Runner(), ['executable' => '/usr/bin/docker', 'context' => 'fixture']),
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
            new Docker($runner, ['executable' => '/usr/bin/docker', 'host' => 'tcp://docker.baander.app:2376']),
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
            new Docker($runner, ['executable' => '/usr/bin/docker', 'context' => 'fixture']),
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
            new Docker(new Runner(), ['executable' => '/usr/bin/docker', 'context' => 'fixture']),
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
        $runner = new Runner();
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
            $result = $runner->run($command, env: $environment);
            $sample = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(9876.5, $sample['monotonic']);
            self::assertSame("100\n", $sample['cgroup']['memory.current']);
            self::assertSame(42, $sample['processes'][0]['pid']);
            $ready = json_decode(file_get_contents($directory . '/ready.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(str_repeat('a', 64), $ready['containerId']);
            self::assertSame(str_repeat('b', 64), $ready['clockContainerId']);
            self::assertSame('docker-daemon-php-monotonic', $ready['clockSource']);
            $commands = str_replace('\\/', '/', file_get_contents($directory . '/commands.jsonl'));
            self::assertStringContainsString('/proc/[0-9]*', $commands);
            self::assertStringContainsString('smaps_rollup', $commands);
            unlink($directory . '/ready.json');
            $failed = $runner->run($command, env: array_replace($environment, ['WORKER_SAMPLER_EMPTY' => '1']), check: false);
            self::assertSame(1, $failed->exitCode);
            self::assertStringContainsString('no postgres process identity', $failed->stderr);
            self::assertFileDoesNotExist($directory . '/ready.json');
        } finally {
            $runner->run(['rm', '-rf', '--', $directory]);
        }
    }
}

final class WorkerSamplerRecordingRunner extends Runner
{
    /** @var list<non-empty-list<string>> */
    public array $calls = [];
    /** @param non-empty-list<string> $arguments
     * @param array<string,string>|null $env
     */
    public function run(
        array $arguments,
        ?string $cwd = null,
        ?array $env = null,
        ?string $input = null,
        ?float $timeout = 120,
        bool $check = true,
        ?string $logPath = null,
        bool $interactive = false,
    ): ProcessResult {
        $this->calls[] = $arguments;
        return new ProcessResult(0, null, false, false, 't', '', .01);
    }
}
