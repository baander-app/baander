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
            $configuration = new \App\Shared\Application\DTO\WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), 1024 * 1024 * 1024,
                128 * 1024 * 1024, 320 * 1024 * 1024, 320 * 1024 * 1024, '/tmp/baander-worker-locks', $reservation);
            foreach (['consumer', 'relay'] as $role) {
                $definition = new \App\Shared\Infrastructure\Worker\WorkerDefinition($role, [PHP_BINARY, '-r', 'exit(0);'], $project, 320 * 1024 * 1024);
                $granted = $method->invoke($runner, $environment, $definition, $configuration);
                self::assertSame((string) ($role === 'consumer' ? $reservation : 0), $granted['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES']);
            }
        }
    }

    public function testNonPidOneInvocationIsRejectedBeforeCreatingLockOrLaunching(): void
    {
        $directory = sys_get_temp_dir() . '/baander-runner-rejected-' . bin2hex(random_bytes(8));
        $project = dirname(__DIR__, 5);
        $code = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
$configuration = new \App\Shared\Application\DTO\WorkerRuntimeConfiguration('worker.baander.app', str_repeat('a', 32), 768 * 1024 * 1024,
    128 * 1024 * 1024, 320 * 1024 * 1024, 320 * 1024 * 1024, $argv[2]);
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
