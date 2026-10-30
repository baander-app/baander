<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Inventory\Collector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryQualificationTest extends TestCase
{
    private string $root;
    /** @var array<string,string> */
    private array $environment;

    public function testTimedOutZeroExitFailsEvenWhenDiagnosticExitIsPermitted(): void
    {
        $qualification = new \Baander\Tooling\Registry\QualificationRunner($this->root);
        $reflection = new \ReflectionObject($qualification);
        $reflection->getProperty('work')->setValue($qualification, $this->root);
        $signals = new \Baander\Tooling\Process\SignalScope();
        $reflection->getProperty('signals')->setValue($qualification, $signals);
        try {
            try {
                $reflection->getMethod('command')->invokeArgs($qualification, [
                    'argv' => [PHP_BINARY, '-r', 'pcntl_async_signals(true); pcntl_signal(15, static function (): never { exit(0); }); usleep(30000000);'],
                    'requireZero' => false,
                    'timeout' => 1.0,
                ]);
                self::fail('An interrupted diagnostic command must not pass.');
            } catch (\Baander\Tooling\Process\CommandFailure $failure) {
                self::assertSame(124, $failure->getCode());
                self::assertNotNull($failure->result);
                self::assertTrue($failure->result->timedOut);
                self::assertSame(0, $failure->result->exitCode);
            }
        } finally {
            $reflection->getProperty('runner')->getValue($qualification)->close();
            $signals->close();
        }
    }

    public function testPackageLoaderAndInjectedStorageValidationNeedNoRelayTestSupport(): void
    {
        $result = new \Baander\Tooling\Process\ProcessRunner()->run(new \Baander\Tooling\Process\ProcessSpec(
            [PHP_BINARY, __DIR__ . '/Fixtures/registry-storage-injection.php', $this->root],
            environment: array_replace(getenv(), [
                'REGISTRY_FULL_DISK_ROOT' => $this->root,
                'CI' => '1',
                'REGISTRY_CLUSTER_NODES' => '3',
                'REGISTRY_QUALIFICATION_OUTPUT' => '',
            ]),
            timeout: 10,
            processGroup: true,
        ));
        self::assertSame(31, $result->exitCode, $result->stdout . $result->stderr);
        self::assertStringContainsString('Explicit storage validation reached without test support.', $result->stderr);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-registry-qualification-test-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/bin', 0700, true);
        mkdir($this->root . '/relay/tests/parser_corpus', 0700, true);
        foreach ([
            'curl',
            'tar',
            'sha256sum',
            'cmake',
            'ctest',
            'make',
            'clang',
            'clang++',
            'git',
            'c++',
            'ninja',
            'perl',
            'openssl',
            'pkg-config',
        ] as $command) {
            symlink(__DIR__ . '/Fixtures/registry-command.php', $this->root . '/bin/' . $command);
        }
        foreach ([
            'test_cluster_cleanup',
            'test_storage_cleanup',
            'run_rqlite_contract',
            'run_transport_contract',
            'run_http_contract',
            'run_cluster_contract',
            'run_restore_contract',
        ] as $contract) {
            copy(__DIR__ . '/Fixtures/registry-command.php', $this->root . '/relay/tests/' . $contract . '.php');
        }
        $this->environment = array_replace(
            getenv(),
            [
                'PATH' => $this->root . '/bin:' . getenv('PATH'),
                'CC' => 'clang',
                'CXX' => 'clang++',
                'REGISTRY_TEST_STATE' => $this->root,
                'CI' => '1',
                'REGISTRY_QUALIFICATION_OUTPUT' => $this->root . '/output',
                'REGISTRY_SUBJECT_PYTHON' => PHP_BINARY,
            ],
        );
        foreach (['REGISTRY_CLUSTER_NODES', 'REGISTRY_FUZZ_RUNS', 'REGISTRY_FUZZ_SECONDS'] as $key) {
            unset($this->environment[$key]);
        }
    }

    protected function tearDown(): void
    {
        Collector::remove($this->root);
    }

    /** @param array<string,string> $settings
     * @return array{int,string,string}
     */
    private function recipe(array $settings = []): array
    {
        [$process, $pipes] = $this->start($settings);
        return $this->finish($process, $pipes);
    }

    /** @param array<string,string> $settings
     * @return array{resource,array<int,resource>}
     */
    private function start(array $settings): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/registry-qualification.php', $this->root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: array_replace($this->environment, $settings),
        );
        self::assertIsResource($process);
        return [$process, $pipes];
    }

    /** @param resource $process
     * @param array<int,resource> $pipes
     * @return array{int,string,string}
     */
    private function finish($process, array $pipes): array
    {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    private function assertWorkRemoved(): void
    {
        $calls = file($this->root . '/calls', FILE_IGNORE_NEW_LINES) ?: [];
        $download = array_find($calls, static fn(string $line): bool => str_starts_with($line, 'curl '));
        $work = dirname(explode(' -o ', $download, 2)[1]);
        self::assertDirectoryDoesNotExist($work);
    }

    public function testDownloadFailurePreservesStatusDiagnosticsAndCleanup(): void
    {
        [$status, $stdout, $stderr] = $this->recipe(['STUB_FAIL' => 'download']);
        self::assertSame(19, $status, $stdout . $stderr);
        self::assertStringContainsString('exit_status=19', (string) file_get_contents($this->root . '/output/metadata.env'));
        self::assertStringContainsString('injected download failure', (string) file_get_contents($this->root . '/output/console.log'));
        $this->assertWorkRemoved();
    }

    public function testOutputRemainsOptIn(): void
    {
        [$status, $stdout, $stderr] = $this->recipe(['REGISTRY_QUALIFICATION_OUTPUT' => '']);
        self::assertSame(0, $status, $stdout . $stderr);
        self::assertDirectoryDoesNotExist($this->root . '/output');
        self::assertStringNotContainsString('--diagnostics', (string) file_get_contents($this->root . '/calls'));
        $this->assertWorkRemoved();
    }

    public function testFiveVotersAndMetadataAreForwarded(): void
    {
        [$status, $stdout, $stderr] = $this->recipe(['REGISTRY_CLUSTER_NODES' => '5', 'REGISTRY_FUZZ_RUNS' => '7', 'REGISTRY_FUZZ_SECONDS' => '2']);
        self::assertSame(0, $status, $stdout . $stderr);
        $calls = (string) file_get_contents($this->root . '/calls');
        self::assertStringContainsString('run_cluster_contract.php --nodes 5', $calls);
        self::assertStringContainsString('run_restore_contract.php --nodes 5', $calls);
        $metadata = (string) file_get_contents($this->root . '/output/metadata.env');
        foreach (['cluster_nodes=5', 'fuzz_runs=7', 'fuzz_seconds=2', 'exit_status=0', 'revision='] as $field) {
            self::assertStringContainsString($field, $metadata);
        }
        self::assertFileExists($this->root . '/output/cluster/node-0.log');
        self::assertFileExists($this->root . '/output/restore/node-0.log');
        $this->assertWorkRemoved();
    }

    public function testDefaultVoterCountIsThree(): void
    {
        [$status, $stdout, $stderr] = $this->recipe();
        self::assertSame(0, $status, $stdout . $stderr);
        self::assertStringContainsString('run_cluster_contract.php --nodes 3', (string) file_get_contents($this->root . '/calls'));
    }

    public function testScenarioDiagnosticsExcludeSecretsAndDatabaseMaterial(): void
    {
        require_once dirname(__DIR__, 3) . '/relay/tests/bootstrap.php';
        $source = $this->root . '/scenario';
        mkdir($source);
        foreach (['node-0.log', 'pinned.log', 'ca.key', 'auth.json', 'backup.sqlite'] as $name) {
            file_put_contents($source . '/' . $name, $name);
        }
        mkdir($source . '/node-0');
        file_put_contents($source . '/node-0/raft.db', 'database');
        \Baander\RegistryTests\ClusterContract::exportDiagnostics($source, $this->root . '/diagnostics');
        $retained = array_diff(scandir($this->root . '/diagnostics') ?: [], ['.', '..']) |> array_values(...);
        self::assertSame(['node-0.log', 'pinned.log'], $retained);
    }

    public function testInvalidVotersAndNonemptyOutputFailBeforeWork(): void
    {
        self::assertSame(2, $this->recipe(['REGISTRY_CLUSTER_NODES' => '4'])[0]);
        self::assertFileDoesNotExist($this->root . '/calls');
        mkdir($this->root . '/output');
        file_put_contents($this->root . '/output/keep', 'existing');
        self::assertSame(2, $this->recipe()[0]);
        self::assertFileDoesNotExist($this->root . '/calls');
        self::assertSame('existing', file_get_contents($this->root . '/output/keep'));
    }

    public function testScenarioFailureStopsLaterGatesAndKeepsDiagnostics(): void
    {
        [$status, $stdout, $stderr] = $this->recipe(['STUB_FAIL' => 'cluster']);
        self::assertSame(23, $status, $stdout . $stderr);
        self::assertStringContainsString('exit_status=23', (string) file_get_contents($this->root . '/output/metadata.env'));
        self::assertStringNotContainsString('run_restore_contract.php', (string) file_get_contents($this->root . '/calls'));
        self::assertFileExists($this->root . '/output/cluster/node-0.log');
        $this->assertWorkRemoved();
    }

    public function testFuzzerFailureRetainsReproducer(): void
    {
        self::assertSame(29, $this->recipe(['STUB_FAIL' => 'fuzz'])[0]);
        self::assertSame('input', file_get_contents($this->root . '/output/fuzz-artifacts/crash-input'));
        self::assertStringContainsString('parser output', (string) file_get_contents($this->root . '/output/fuzz.log'));
        $this->assertWorkRemoved();
    }

    /** @return iterable<string,array{int,string,int}> */
    public static function tsanCases(): iterable
    {
        yield 'detected race' => [66, 'WARNING: ThreadSanitizer: data race', 0];
        yield 'missed race' => [0, 'WARNING: ThreadSanitizer: data race', 1];
        yield 'unavailable runtime' => [66, 'FATAL: ThreadSanitizer: unexpected memory mapping', 1];
    }

    #[DataProvider('tsanCases')]
    public function testTsanRequiresBothRaceDiagnosticAndExpectedExit(int $code, string $diagnostic, int $expected): void
    {
        $probe = $this->root . '/tsan-probe';
        $source = '#!' . PHP_BINARY . "\n<?php\n"
            . 'if (getenv("TSAN_OPTIONS") !== "halt_on_error=1:exitcode=66") { exit(7); }' . "\n"
            . 'fwrite(STDERR, ' . var_export($diagnostic, true) . '); exit(' . $code . ');';
        file_put_contents($probe, $source);
        chmod($probe, 0755);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/relay/tests/verify_tsan.php', '--probe', $probe],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        [$status, $stdout, $stderr] = $this->finish($process, $pipes);
        self::assertSame($expected, $status, $stdout . $stderr);
        self::assertStringContainsString($expected === 0 ? 'detected the deliberate data race' : 'positive control failed', $stdout . $stderr);
    }

    /** @return iterable<string,array{string}> */
    public static function interruptionCases(): iterable
    {
        yield 'graceful' => ['terminate'];
        yield 'stubborn' => ['stubborn'];
    }

    #[DataProvider('interruptionCases')]
    public function testCancellationReapsScenarioBeforeDeletingWork(string $behavior): void
    {
        [$process, $pipes] = $this->start(['STUB_FAIL' => $behavior]);
        $deadline = microtime(true) + 10;
        while (!is_file($this->root . '/child') && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (!is_file($this->root . '/child')) {
            proc_terminate($process, 9);
            self::fail('Controlled scenario did not start');
        }
        $child = (int) file_get_contents($this->root . '/child');
        $storage = (string) file_get_contents($this->root . '/storage');
        \Swoole\Process::kill(proc_get_status($process)['pid'], 15);
        [$status, $stdout, $stderr] = $this->finish($process, $pipes);
        self::assertSame(143, $status, $stdout . $stderr);
        self::assertFalse(is_dir('/proc/' . $child));
        self::assertDirectoryDoesNotExist($storage);
        self::assertStringContainsString('exit_status=143', (string) file_get_contents($this->root . '/output/metadata.env'));
        $this->assertWorkRemoved();
    }
}
