<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\WorkerChildProcess;
use PHPUnit\Framework\TestCase;

final class WorkerSupervisorRunnerTest extends TestCase
{
    public function testFreshDotenvCannotOverwriteExplicitChildIdentityFromInheritedParentMarker(): void
    {
        $project = dirname(__DIR__, 5);
        $path = tempnam(sys_get_temp_dir(), 'baander-worker-dotenv-');
        self::assertIsString($path);
        file_put_contents($path, "MESSENGER_CONSUMER_NAME=dotenv-default.baander.app\nBAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES=999999999\n");
        $previousEnv = $_ENV;
        $previousServer = $_SERVER;
        $stdout = tmpfile();
        $stderr = tmpfile();
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $child = null;
        try {
            $_ENV['SYMFONY_DOTENV_VARS'] = 'MESSENGER_CONSUMER_NAME,BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES';
            $_SERVER['SYMFONY_DOTENV_VARS'] = 'MESSENGER_CONSUMER_NAME,BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES';
            $_ENV['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES'] = '999999999';
            $_SERVER['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES'] = '999999999';
            $runner = new \App\Shared\Infrastructure\Worker\WorkerSupervisorRunner($project, 'postgresql://worker:private@db.baander.app/worker');
            $environment = (new \ReflectionMethod($runner, 'environment'))->invoke($runner);
            $expected = 'worker-namespace-boot-consumer-1';
            $environment['MESSENGER_CONSUMER_NAME'] = $expected;
            $code = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
(new \Symfony\Component\Dotenv\Dotenv())->usePutenv()->load($argv[2]);
echo $_ENV['MESSENGER_CONSUMER_NAME'];
echo ':' . $_ENV['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES'];
PHP;
            $child = WorkerChildProcess::start([PHP_BINARY, '-r', $code, '--', $project, $path], $project, $stdout, $stderr, $environment);
            $deadline = hrtime(true) / 1e9 + 5;
            while ($child->poll(hrtime(true) / 1e9)) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    $child->requestStop(hrtime(true) / 1e9, 0);
                    self::fail('Fresh Dotenv subprocess did not exit within five seconds.');
                }
                usleep(1000);
            }
            $diagnostic = file_get_contents(stream_get_meta_data($stderr)['uri'], false, null, 0, 4096);
            self::assertSame(0, $child->exitCode(), $diagnostic === false ? 'Cannot read Dotenv diagnostics.' : $diagnostic);
            self::assertSame($expected . ':0', file_get_contents(stream_get_meta_data($stdout)['uri'], false, null, 0, 4096));
        } finally {
            unset($child);
            $_ENV = $previousEnv;
            $_SERVER = $previousServer;
            fclose($stdout);
            fclose($stderr);
            unlink($path);
        }
    }

    public function testConsoleReservationIsGrantedOnlyToConsumerAndDisabledByDefault(): void
    {
        $project = dirname(__DIR__, 5);
        $runner = new \App\Shared\Infrastructure\Worker\WorkerSupervisorRunner($project, 'postgresql://worker:private@db.baander.app/worker');
        $environment = ['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES' => '999999999'];
        $method = new \ReflectionMethod($runner, 'roleEnvironment');
        foreach ([0, 192 * 1024 * 1024] as $reservation) {
            $configuration = new \App\Shared\Application\DTO\WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), 1280 * 1024 * 1024,
                128 * 1024 * 1024, 320 * 1024 * 1024, 320 * 1024 * 1024, '/tmp/baander-worker-locks', $reservation, 320 * 1024 * 1024);
            foreach (['consumer', 'relay', 'scheduler'] as $role) {
                $definition = new \App\Shared\Infrastructure\Worker\WorkerDefinition($role, [PHP_BINARY, '-r', 'exit(0);'], $project, 320 * 1024 * 1024);
                $granted = $method->invoke($runner, $environment, $definition, $configuration);
                self::assertSame((string) ($role === 'consumer' ? $reservation : 0), $granted['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES']);
            }
        }
    }

    public function testResolvedBooleanDebugOverridesInheritedTextWithCanonicalValue(): void
    {
        $project = dirname(__DIR__, 5);
        $runner = new \App\Shared\Infrastructure\Worker\WorkerSupervisorRunner($project, 'postgresql://worker:private@db.baander.app/worker');
        $previous = $_SERVER;
        try {
            foreach ([false => '0', true => '1'] as $debug => $expected) {
                $_SERVER['APP_DEBUG'] = (bool) $debug;
                $environment = (new \ReflectionMethod($runner, 'environment'))->invoke($runner);
                self::assertSame($expected, $environment['APP_DEBUG']);
            }
        } finally {
            $_SERVER = $previous;
        }
    }

    public function testPrivateSchedulerEntryPointPreservesInheritedConfigurationAndRedactsFailures(): void
    {
        $project = dirname(__DIR__, 5);
        $directory = sys_get_temp_dir() . '/baander-scheduler-entry-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        mkdir($directory . '/vendor', 0700);
        copy($project . '/bin/worker-scheduler.php', $directory . '/bin/worker-scheduler.php');
        file_put_contents($directory . '/.env', "APP_ENV=dotenv-baander-app\nAPP_DEBUG=1\nBAANDER_WORKER_ID=consumer\n");
        $stub = <<<'PHP'
<?php
namespace App {
    class Kernel {
        public function __construct(public string $environment, public bool $debug) { $GLOBALS['testKernel'] = $this; }
        public function boot(): void {}
        public function shutdown(): void {}
        public function getContainer(): object {
            return new class {
                public function get(string $alias): object {
                    if ($alias !== 'scheduler.worker_runner') { throw new \RuntimeException('Unexpected alias.'); }
                    return new \App\Scheduler\Infrastructure\Process\SchedulerWorkerRunner();
                }
            };
        }
    }
}
namespace App\Scheduler\Infrastructure\Process {
    class SchedulerWorkerRunner {
        public function runUntilSignalled(): void {
            if (getenv('BAANDER_TEST_FAIL') === '1') { throw new \RuntimeException('private-database-password'); }
            echo json_encode([$GLOBALS['testKernel']->environment, $GLOBALS['testKernel']->debug,
                getenv('BAANDER_WORKER_ID'), getenv('BAANDER_WORKER_GENERATION'), getenv('BAANDER_WORKER_LEASE_EPOCH')], JSON_THROW_ON_ERROR);
        }
    }
}
namespace { require __AUTOLOAD__; }
PHP;
        file_put_contents($directory . '/vendor/autoload.php', str_replace('__AUTOLOAD__', var_export($project . '/vendor/autoload.php', true), $stub));
        try {
            foreach (['success', 'runner failure', 'wrong role'] as $scenario) {
                $stdout = tmpfile();
                $stderr = tmpfile();
                self::assertIsResource($stdout);
                self::assertIsResource($stderr);
                $child = null;
                try {
                    $environment = ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'DATABASE_URL' => 'private-database-password',
                        'BAANDER_WORKER_NAMESPACE' => 'worker.baander.app', 'BAANDER_WORKER_BOOT_ID' => str_repeat('a', 32),
                        'BAANDER_WORKER_ID' => $scenario === 'wrong role' ? 'consumer' : 'scheduler',
                        'BAANDER_WORKER_GENERATION' => '7', 'BAANDER_WORKER_LEASE_EPOCH' => '42',
                        'BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES' => '0', 'BAANDER_TEST_FAIL' => $scenario === 'runner failure' ? '1' : '0'];
                    $child = WorkerChildProcess::start([PHP_BINARY, $directory . '/bin/worker-scheduler.php'], $directory, $stdout, $stderr, $environment);
                    $deadline = hrtime(true) / 1e9 + 5;
                    while ($child->poll(hrtime(true) / 1e9)) {
                        if (hrtime(true) / 1e9 >= $deadline) {
                            $child->requestStop(hrtime(true) / 1e9, 0);
                            self::fail('Private scheduler subprocess did not exit within five seconds.');
                        }
                        usleep(1000);
                    }
                    $output = file_get_contents(stream_get_meta_data($stdout)['uri']);
                    $diagnostic = file_get_contents(stream_get_meta_data($stderr)['uri']);
                    self::assertIsString($output);
                    self::assertIsString($diagnostic);
                    self::assertStringNotContainsString('private-database-password', $output . $diagnostic);
                    if ($scenario === 'success') {
                        self::assertSame(0, $child->exitCode(), $diagnostic);
                        self::assertSame(['prod', false, 'scheduler', '7', '42'], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
                        self::assertSame('', $diagnostic);
                    } else {
                        self::assertSame(1, $child->exitCode());
                        self::assertSame('', $output);
                        self::assertSame("Scheduler worker failed; supervisor reconciliation is required.\n", $diagnostic);
                    }
                } finally {
                    unset($child);
                    fclose($stdout);
                    fclose($stderr);
                }
            }
        } finally {
            unlink($directory . '/bin/worker-scheduler.php');
            unlink($directory . '/vendor/autoload.php');
            unlink($directory . '/.env');
            rmdir($directory . '/bin');
            rmdir($directory . '/vendor');
            rmdir($directory);
        }
    }

    public function testSchedulerHasItsOwnReservationAndPrivateEntryPoint(): void
    {
        $project = dirname(__DIR__, 5);
        $runner = new \App\Shared\Infrastructure\Worker\WorkerSupervisorRunner($project, 'postgresql://worker:private@db.baander.app/worker');
        $configuration = new \App\Shared\Application\DTO\WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), 1280 * 1024 * 1024,
            128 * 1024 * 1024, 320 * 1024 * 1024, 320 * 1024 * 1024, '/tmp/baander-worker-locks', 192 * 1024 * 1024, 320 * 1024 * 1024);
        $definitions = (new \ReflectionMethod($runner, 'definitions'))->invoke($runner, $configuration);
        self::assertSame(['consumer', 'relay', 'scheduler'], array_column($definitions, 'id'));
        self::assertSame([PHP_BINARY, '-d', 'memory_limit=256M', $project . '/bin/worker-scheduler.php'], $definitions[2]->argv);
        self::assertSame(320 * 1024 * 1024, $definitions[2]->memoryReservationBytes);
        self::assertSame(0, $definitions[2]->descendantProcessReservation);
        self::assertSame(512 * 1024 * 1024, $definitions[0]->memoryReservationBytes);
        self::assertSame(1, $definitions[0]->descendantProcessReservation);
        self::assertSame([PHP_BINARY, '-d', 'memory_limit=256M', $project . '/bin/console',
            'messenger:consume', 'async', 'scheduler', '--memory-limit=256M', '--keepalive=30', '--no-interaction'], $definitions[0]->argv);
        self::assertSame($configuration->memoryLimitBytes, $configuration->managementReservationBytes + array_sum(array_column($definitions, 'memoryReservationBytes')));
    }

    public function testSchedulerLaunchEnvironmentUsesCurrentCommittedEpochAndGeneration(): void
    {
        $project = dirname(__DIR__, 5);
        $runner = new \App\Shared\Infrastructure\Worker\WorkerSupervisorRunner($project, 'postgresql://worker:private@db.baander.app/worker');
        $configuration = new \App\Shared\Application\DTO\WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), 1088 * 1024 * 1024,
            128 * 1024 * 1024, 320 * 1024 * 1024, 320 * 1024 * 1024, '/tmp/baander-worker-locks', 0, 320 * 1024 * 1024);
        $definition = (new \ReflectionMethod($runner, 'definitions'))->invoke($runner, $configuration)[2];
        $identity = new \App\Shared\Infrastructure\Worker\WorkerLaunchIdentity($configuration->namespace, $configuration->bootId, 'scheduler', 7);
        $authority = new \App\Shared\Infrastructure\Worker\LeaseAuthority($configuration->namespace, $configuration->bootId, 30, 10, 1);
        $method = new \ReflectionMethod($runner, 'childEnvironment');
        try {
            $method->invoke($runner, [], $definition, $configuration, $identity, $authority, 10.0);
            self::fail('An uncommitted lease must not grant scheduler admission.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('committed', $error->getMessage());
        }
        $request = $authority->request(10.0);
        self::assertNotNull($request);
        self::assertTrue($authority->accept($request['sequence'], new \App\Shared\Infrastructure\Worker\DeploymentLease($configuration->namespace, $configuration->bootId, 42), 11.0));
        $environment = $method->invoke($runner, ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'BAANDER_WORKER_LEASE_EPOCH' => '999',
            'BAANDER_WORKER_GENERATION' => '999', 'BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES' => '999'], $definition, $configuration, $identity, $authority, 12.0);
        self::assertSame($configuration->namespace, $environment['BAANDER_WORKER_NAMESPACE']);
        self::assertSame($configuration->bootId, $environment['BAANDER_WORKER_BOOT_ID']);
        self::assertSame('scheduler', $environment['BAANDER_WORKER_ID']);
        self::assertSame('7', $environment['BAANDER_WORKER_GENERATION']);
        self::assertSame('42', $environment['BAANDER_WORKER_LEASE_EPOCH']);
        self::assertSame('0', $environment['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES']);
        self::assertSame('prod', $environment['APP_ENV']);
        self::assertSame('0', $environment['APP_DEBUG']);
        $authority->revoke(13.0);
        $this->expectException(\RuntimeException::class);
        $method->invoke($runner, [], $definition, $configuration, $identity, $authority, 14.0);
    }

    public function testNonPidOneInvocationIsRejectedBeforeCreatingLockOrLaunching(): void
    {
        $directory = sys_get_temp_dir() . '/baander-runner-rejected-' . bin2hex(random_bytes(8));
        $project = dirname(__DIR__, 5);
        $code = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
$configuration = new \App\Shared\Application\DTO\WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), 1088 * 1024 * 1024,
    128 * 1024 * 1024, 320 * 1024 * 1024, 320 * 1024 * 1024, $argv[2], 0, 320 * 1024 * 1024);
$runner = new \App\Shared\Infrastructure\Worker\WorkerSupervisorRunner($argv[1], 'postgresql://worker:private@db.baander.app/worker');
try {
    $runner->run($configuration);
    fwrite(STDERR, 'Uncontained invocation was not rejected.');
    exit(2);
} catch (\RuntimeException $error) {
    echo json_encode(['pid' => getmypid(), 'message' => $error->getMessage(), 'lockAbsent' => !file_exists($argv[2])], JSON_THROW_ON_ERROR);
}
PHP;
        $stdout = tmpfile();
        $stderr = tmpfile();
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $child = null;
        try {
            // PHPUnit itself can be deployment PID 1 in the canonical container.
            // The tested invocation is always a fresh, uncontained direct child.
            $child = WorkerChildProcess::start([PHP_BINARY, '-r', $code, '--', $project, $directory], $project, $stdout, $stderr);
            $deadline = hrtime(true) / 1e9 + 5;
            while ($child->poll(hrtime(true) / 1e9)) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    $child->requestStop(hrtime(true) / 1e9, 0);
                    self::fail('Guard subprocess did not exit within five seconds.');
                }
                usleep(1000);
            }
            $diagnostic = file_get_contents(stream_get_meta_data($stderr)['uri'], false, null, 0, 4096);
            self::assertSame(0, $child->exitCode(), $diagnostic === false ? 'Cannot read guard diagnostics.' : $diagnostic);
            $raw = file_get_contents(stream_get_meta_data($stdout)['uri'], false, null, 0, 4096);
            self::assertIsString($raw);
            $result = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            self::assertNotSame(1, $result['pid']);
            self::assertStringContainsString('PID 1', $result['message']);
            self::assertStringNotContainsString('private', $result['message']);
            self::assertTrue($result['lockAbsent']);
            self::assertDirectoryDoesNotExist($directory);
        } finally {
            unset($child);
            fclose($stdout);
            fclose($stderr);
        }
    }
}
