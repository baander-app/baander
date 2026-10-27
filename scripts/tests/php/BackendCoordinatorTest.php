<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Backend\{Coordinator, Runtime};
use Baander\Tooling\Development\{Docker, Environment, Runner, WorkflowError};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackendCoordinatorTest extends TestCase
{
    private string $directory;
    private Runner $runner;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-backend-tooling-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->runner = new Runner();
    }

    protected function tearDown(): void
    {
        $this->runner->run(['rm', '-rf', '--', $this->directory]);
    }

    /** @param array<string,string>|null $environment */
    private function runtime(?array $environment = null): Runtime
    {
        return new Runtime($this->directory, 60, dirname(__DIR__, 3), $environment);
    }

    public function testDiscoveryPartitionsNativeOrdinaryAndRoundRobinServiceFilesExactlyOnce(): void
    {
        $files = [
            'tests/Unit/OrdinaryTest.php',
            'tests/Unit/NativeTest.php',
            'packages/baander-phpstan-rules/tests/RuleTest.php',
        ];
        for ($index = 0; $index < 7; ++$index) {
            $files[] = 'tests/Integration/Case' . $index . 'Test.php';
        }
        $lanes = Coordinator::partition($files, ['tests/Unit/NativeTest.php' => 'native reactor'], 3);
        self::assertSame(['tests/Unit/NativeTest.php'], $lanes['native']['files']);
        self::assertSame(
            [
                'files' => ['packages/baander-phpstan-rules/tests/RuleTest.php', 'tests/Unit/OrdinaryTest.php'],
                'processes' => 2,
                'batch' => 25,
                'services' => false,
                'privileged' => false,
            ],
            $lanes['ordinary'],
        );
        self::assertSame(
            [
                'tests/Integration/Case0Test.php',
                'tests/Integration/Case3Test.php',
                'tests/Integration/Case6Test.php',
            ],
            $lanes['stack-1']['files'],
        );
        $assigned = array_merge(...array_column($lanes, 'files'));
        sort($files);
        sort($assigned);
        self::assertSame($files, $assigned);
        foreach (['native', 'stack-1', 'stack-2', 'stack-3'] as $lane) {
            self::assertSame(1, $lanes[$lane]['processes']);
            self::assertSame(1, $lanes[$lane]['batch']);
            self::assertTrue($lanes[$lane]['privileged']);
        }
        self::assertFalse($lanes['native']['services']);
        self::assertTrue($lanes['stack-1']['services']);
    }

    public function testAbsoluteNativePathsAndFewerServiceFilesRetainLaneSemantics(): void
    {
        $lanes = Coordinator::partition(
            ['/var/www/html/tests/Unit/NativeTest.php', 'tests/Functional/OnlyTest.php'],
            ['tests/Unit/NativeTest.php' => 'native'],
            8,
        );
        self::assertCount(2, $lanes);
        self::assertArrayHasKey('native', $lanes);
        self::assertArrayHasKey('stack-1', $lanes);
    }

    /** @return iterable<string,array{list<string>}> */
    public static function invalidDiscovery(): iterable
    {
        yield 'empty' => [[]];
        yield 'duplicate' => [['tests/Unit/Test.php', 'tests/Unit/Test.php']];
    }

    /** @param list<string> $files */
    #[DataProvider('invalidDiscovery')]
    public function testInvalidDiscoveryRejected(array $files): void
    {
        $this->expectException(InvalidArgumentException::class);
        Coordinator::partition($files, [], 2);
    }

    public function testWorkerCountBoundaries(): void
    {
        self::assertSame(1, Coordinator::positiveInteger('1', 'workers', 8));
        self::assertSame(8, Coordinator::positiveInteger('8', 'workers', 8));
        foreach (['0', '-1', '9', '1.5', 'two', '', '01', str_repeat('9', 100)] as $value) {
            try {
                Coordinator::positiveInteger($value, 'workers', 8);
                self::fail('Accepted invalid count');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('workers', $error->getMessage());
            }
        }
    }

    public function testNativeManifestNamesExistingTestsWithReasons(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(file_get_contents($root . '/scripts/backend-native-tests.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($manifest);
        foreach ($manifest as $path => $reason) {
            self::assertFileExists($root . '/' . $path);
            self::assertStringEndsWith('Test.php', $path);
            self::assertNotSame('', trim($reason));
        }
    }

    public function testExpiredAndInterruptedRunsDoNotStartCommands(): void
    {
        $runtime = $this->runtime();
        try {
            $runtime->deadline = -1;
            try {
                $runtime->run([PHP_BINARY, '-r', 'file_put_contents($argv[1], "wrong");', $this->directory . '/started']);
                self::fail('Started expired command');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('timeout', $error->getMessage());
            }
            $runtime->deadline = Runtime::now() + 60;
            $runtime->stopped = true;
            try {
                $runtime->run([PHP_BINARY, '-r', 'file_put_contents($argv[1], "wrong");', $this->directory . '/started']);
                self::fail('Started interrupted command');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('interrupted', $error->getMessage());
            }
            self::assertFileDoesNotExist($this->directory . '/started');
        } finally {
            $runtime->close();
        }
    }

    public function testTimeoutRetainsStreamedLogsAndStopsLaterCommands(): void
    {
        $runtime = $this->runtime();
        try {
            try {
                $runtime->run(
                    [PHP_BINARY, '-r', 'echo "before timeout"; usleep(2000000);'],
                    timeout: 0.1,
                    logPath: $this->directory . '/timeout.log',
                );
                self::fail('Timeout did not fail');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('timeout', $error->getMessage());
            }
            self::assertTrue($runtime->stopped);
            self::assertSame('before timeout', file_get_contents($this->directory . '/timeout.log'));
        } finally {
            $runtime->close();
        }
    }

    /** @return array<string,mixed> */
    private function before(): array
    {
        return [
            'php_version' => '8.5.2',
            'binary' => '/usr/local/bin/php',
            'sapi' => 'cli',
            'php_ini' => '/usr/local/etc/php/php.ini',
            'configuration' => [
                '/usr/local/etc/php/php.ini' => 'main',
                Runtime::SWOOLE_LOADER => 'loader',
                '/usr/local/etc/php/conf.d/redis.ini' => 'redis',
            ],
            'extensions' => ['Core', 'redis', 'swoole'],
            'native_symbols' => ['Swoole\\Process'],
            'loader' => "extension=swoole\n",
            'loader_symlink' => false,
            'autoload' => false,
            'xdebug_mode' => 'coverage',
            'xdebug_environment' => 'coverage',
        ];
    }

    public function testDedicatedSwooleRemovalPreservesEveryOtherRuntimeSetting(): void
    {
        $before = $this->before();
        $after = $before;
        unset($after['configuration'][Runtime::SWOOLE_LOADER]);
        $after = array_replace(
            $after,
            ['extensions' => ['Core', 'redis'], 'native_symbols' => [], 'loader' => null, 'autoload' => true],
        );
        Runtime::validateSwooleRuntime($before);
        Runtime::validateSwooleRuntime($before, $after);
        foreach ([
            ['extensions' => ['Core']],
            ['native_symbols' => ['Swoole\\Process']],
            ['configuration' => []],
            ['autoload' => false],
            ['php_version' => '8.6.0'],
            ['xdebug_mode' => 'off'],
            ['xdebug_environment' => 'off'],
            ['loader' => 'extension=swoole'],
        ] as $change) {
            try {
                Runtime::validateSwooleRuntime($before, array_replace($after, $change));
                self::fail('Accepted changed runtime');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Swoole', $error->getMessage());
            }
        }
    }

    public function testUnexpectedSwooleLoaderIsRejectedBeforeRemoval(): void
    {
        foreach ([
            ['loader' => "extension=swoole\nextension=redis"],
            ['loader' => 'extension=other.so'],
            ['loader_symlink' => true],
            ['extensions' => ['Core', 'redis']],
            ['php_ini' => false],
            ['configuration' => []],
        ] as $change) {
            try {
                Runtime::validateSwooleRuntime(array_replace($this->before(), $change));
                self::fail('Accepted unexpected loader');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testInvalidSwooleOptInFailsBeforeArtifactsAreCreated(): void
    {
        foreach (['', 'true', 'yes', '2', '01'] as $value) {
            try {
                Coordinator::main(
                    [],
                    dirname(__DIR__, 3),
                    ['BAANDER_TEST_ARTIFACTS' => $this->directory . '/artifacts', 'BAANDER_TEST_DISABLE_SWOOLE' => $value],
                );
                self::fail('Accepted invalid opt-in');
            } catch (InvalidArgumentException) {
                self::assertDirectoryDoesNotExist($this->directory . '/artifacts');
            }
        }
    }

    /** @return array<string,string> */
    private function fakeEnvironment(string $failure = '', bool $services = false): array
    {
        $bin = $this->directory . '/bin';
        mkdir($bin, 0700);
        $fixture = $bin . '/docker';
        copy(__DIR__ . '/Fixtures/backend-docker.php', $fixture);
        chmod($fixture, 0755);
        return array_replace(
            Environment::clean(),
            [
                'PATH' => $bin . ':' . getenv('PATH'),
                'BACKEND_FAKE_ROOT' => $this->directory,
                'BACKEND_FAIL_STAGE' => $failure,
                'BACKEND_SERVICES' => $services ? '1' : '0',
                'BAANDER_TEST_ARTIFACTS' => $this->directory . '/artifacts',
                'BAANDER_TEST_CHECKOUT_IN_IMAGE' => '1',
            ],
        );
    }

    /** @return list<list<string>> */
    private function commands(): array
    {
        return array_map(
            static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->directory . '/commands.jsonl', FILE_IGNORE_NEW_LINES),
        );
    }

    /** @return iterable<string,array{string,int}> */
    public static function outcomes(): iterable
    {
        yield 'success' => ['', 0];
        yield 'discovery failure' => ['discover', 1];
        yield 'lane failure' => ['run', 1];
        yield 'finalization failure' => ['finalize', 1];
        yield 'cleanup failure' => ['cleanup', 1];
        yield 'cancellation' => ['cancel', 1];
    }

    #[DataProvider('outcomes')]
    public function testCliPreservesOutcomesLogsTimingAndCleanup(string $failure, int $expected): void
    {
        $environment = $this->fakeEnvironment($failure);
        $result = $this->runner->run([PHP_BINARY, dirname(__DIR__, 2) . '/test-backend-container.php'], env: $environment, check: false);
        self::assertSame($expected, $result->exitCode, $result->stdout . $result->stderr);
        $runs = glob($this->directory . '/artifacts/*');
        self::assertCount(1, $runs);
        self::assertSame(
            $expected,
            json_decode(file_get_contents($runs[0] . '/timing.json'), true, flags: JSON_THROW_ON_ERROR)['status'],
        );
        self::assertFileExists($runs[0] . '/resources.json');
        self::assertFileExists($runs[0] . '/after-cleanup-all-volumes.log');
        self::assertTrue(array_any($this->commands(), static fn(array $args): bool => in_array('rm', $args, true)));
        if ($failure === '') {
            self::assertStringContainsString('lane output retained', file_get_contents($runs[0] . '/ordinary.log'));
            self::assertFileExists($runs[0] . '/run/ordinary.result.json');
        }
        if ($failure === 'discover') {
            self::assertStringContainsString('discover fixture failure', file_get_contents($runs[0] . '/discovery.log'));
        }
    }

    public function testServiceLanesUseDisjointComposeProjectsAndExplicitCleanup(): void
    {
        $environment = $this->fakeEnvironment(services: true);
        $result = $this->runner->run([PHP_BINARY, dirname(__DIR__, 2) . '/test-backend-container.php'], env: $environment, check: false);
        self::assertSame(0, $result->exitCode, $result->stdout . $result->stderr);
        $projects = [];
        foreach ($this->commands() as $arguments) {
            if (in_array('down', $arguments, true)) {
                $projects[] = $arguments[array_search('--project-name', $arguments, true) + 1];
                self::assertContains('--volumes', $arguments);
                self::assertContains('--remove-orphans', $arguments);
            }
        }
        self::assertCount(2, array_unique($projects));
        $run = glob($this->directory . '/artifacts/*')[0];
        foreach (['stack-1', 'stack-2'] as $lane) {
            self::assertFileExists($run . '/run/' . $lane . '.result.json');
            $log = file_get_contents($run . '/' . $lane . '.log');
            self::assertStringContainsString('service setup retained', $log);
            self::assertStringContainsString('lane output retained', $log);
        }
        $resources = json_decode(file_get_contents($run . '/resources.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('anonymous-fixture-volume', $resources['volumes']);
    }

    public function testFailedInventoryStillRemovesProjectsAndWritesFailedTiming(): void
    {
        $environment = $this->fakeEnvironment('inventory', true);
        $result = $this->runner->run([PHP_BINARY, dirname(__DIR__, 2) . '/test-backend-container.php'], env: $environment, check: false);
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Cleanup failed', $result->stderr);
        $removals = array_filter($this->commands(), static fn(array $arguments): bool => in_array('down', $arguments, true));
        self::assertCount(2, $removals);
        $run = glob($this->directory . '/artifacts/*')[0];
        self::assertSame(1, json_decode(file_get_contents($run . '/timing.json'), true, flags: JSON_THROW_ON_ERROR)['status']);
        self::assertStringContainsString('inventory fixture failure', file_get_contents($run . '/after-cleanup-all-volumes.log'));
        $logs = glob($run . '/before-cleanup-*-volumes.log');
        self::assertNotEmpty($logs);
        self::assertStringContainsString('inventory fixture failure', file_get_contents($logs[0]));
    }

    /** @return iterable<string,array{array<string,string>,string,string}> */
    public static function dockerTargets(): iterable
    {
        yield 'explicit context' => [
            [
                'BAANDER_TEST_DOCKER_CONTEXT' => 'selected',
                'DOCKER_CONTEXT' => 'ambient',
                'DOCKER_HOST' => 'tcp://docker.baander.app:2376',
            ],
            '--context',
            'selected',
        ];
        yield 'environment context' => [
            ['DOCKER_CONTEXT' => 'ambient', 'DOCKER_HOST' => 'tcp://docker.baander.app:2376'],
            '--context',
            'ambient',
        ];
        yield 'environment host' => [
            [
                'DOCKER_HOST' => 'tcp://docker.baander.app:2376',
                'DOCKER_TLS_VERIFY' => '1',
                'DOCKER_CERT_PATH' => '/tmp/baander-docker-certificates',
            ],
            '--host',
            'tcp://docker.baander.app:2376',
        ];
        yield 'current context' => [[], '--context', 'fixture-current'];
    }

    /** @param array<string,string> $settings */
    #[DataProvider('dockerTargets')]
    public function testDockerTargetPrecedenceAndCleanupStayPinned(array $settings, string $option, string $target): void
    {
        $environment = array_replace($this->fakeEnvironment(), $settings);
        $previousPath = getenv('PATH');
        putenv('PATH=' . $environment['PATH']);
        $runtime = $this->runtime($environment);
        try {
            $runtime->connect();
            $prefix = $runtime->dockerArguments();
            self::assertSame([$option, $target], array_slice($prefix, 1));
            $docker = new Docker(
                $runtime,
                [
                    'executable' => $prefix[0],
                    $option === '--host' ? 'host' : 'context' => $target,
                    'daemon_id' => 'fixture-daemon',
                ],
            );
            self::assertSame('fixture-daemon', $docker->validate()['ID']);
            if ($option === '--host') {
                $observed = json_decode(file_get_contents($this->directory . '/docker-environment.json'), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('1', $observed['tls']);
                self::assertSame('/tmp/baander-docker-certificates', $observed['certificates']);
            }
            $runtime->createApp('target');
            $runtime->stopped = true;
            $runtime->deadline = -1;
            self::assertTrue($runtime->cleanup());
            foreach ($this->commands() as $arguments) {
                if (($arguments[0] ?? '') !== 'context') {
                    self::assertSame([$option, $target], array_slice($arguments, 0, 2));
                }
            }
            $changed = new Docker($runtime, array_replace($docker->configuration, ['daemon_id' => 'different-daemon']));
            $runtime->stopped = false;
            $runtime->deadline = Runtime::now() + 10;
            try {
                $changed->validate();
                self::fail('Accepted changed daemon identity');
            } catch (WorkflowError $error) {
                self::assertStringContainsString('identity changed', $error->getMessage());
            }
        } finally {
            $runtime->close();
            putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        }
    }
}
