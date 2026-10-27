<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Web\ShutdownAssertions;
use Baander\Tooling\Process\{ProcessRunner, ProcessSpec};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebWorkflowTest extends TestCase
{
    private const string PROBE = <<<'LOG'
probe 100.000000 before_shutdown signal=15
probe 100.100000 ping fd=5
probe 100.200000 ping fd=5
probe 100.300000 worker_shutdown_call result=true
client 100.400000 hold_response status=200 body=drained errno=0
client 100.500000 ws_close_frame code=1012 reason=restart
client 100.600000 keepalive_during_drain status=200 body=pong errno=0
probe 104.000000 master_hold_end
client 104.100000 idle_eof bytes=0 errno=0
probe 104.200000 worker_stop id=0 master_wchan=do_wait master_threads=1 connections=0
probe 104.300000 shutdown
LOG;

    public function testProbeRequiresExternallyObservedShutdownOrder(): void
    {
        ShutdownAssertions::probe(self::PROBE);
        self::assertStringContainsString('+4.100000', ShutdownAssertions::timeline(self::PROBE, 100));
    }

    /** @return iterable<string,array{string}> */
    public static function brokenProbes(): iterable
    {
        yield 'repeated before shutdown' => [self::PROBE . "\nprobe 100.010000 before_shutdown signal=15"];
        yield 'timer ended before held response' => [str_replace('client 100.400000 hold_response', 'client 104.400000 hold_response', self::PROBE)];
        yield 'WebSocket frame wrong code' => [str_replace('code=1012', 'code=1000', self::PROBE)];
        yield 'keepalive went to new fd' => [str_replace('probe 100.200000 ping fd=5', 'probe 100.200000 ping fd=6', self::PROBE)];
        yield 'connection closed during timer' => [str_replace('client 104.100000 idle_eof', 'client 103.100000 idle_eof', self::PROBE)];
        yield 'worker stopped while reactor threads alive' => [str_replace('master_threads=1', 'master_threads=2', self::PROBE)];
        yield 'worker has connection left' => [str_replace('connections=0', 'connections=1', self::PROBE)];
        yield 'missing shutdown callback' => [str_replace('probe 104.300000 shutdown', 'probe 104.300000 other', self::PROBE)];
    }

    #[DataProvider('brokenProbes')]
    public function testProbeNegativeControlsFail(string $text): void
    {
        $this->expectException(RuntimeException::class);
        ShutdownAssertions::probe($text);
    }

    public function testShutdownRequiresOneDrainAndCorrectWorkerWindow(): void
    {
        $log = "Web server drain started: deadline 10 s, window 3 s, worker stop wait 1 s.\n"
            . "Swoole HTTP Server has been successfully shutdown\n"
            . "Drain window closed after 0.4 s, every worker drained; reset 0 client connection(s).\n";
        ShutdownAssertions::drainCount($log, 0);
        self::assertSame(.4, ShutdownAssertions::drainedWindow($log, true));
        $this->expectException(RuntimeException::class);
        ShutdownAssertions::drainCount($log . $log, 0);
    }

    public function testMissingWorkerDrainCannotPassItsWindow(): void
    {
        $this->expectException(RuntimeException::class);
        ShutdownAssertions::drainedWindow('Drain window closed after 3.0 s, the window passed; reset 1 client connection(s).', true);
    }

    public function testKeepaliveResponsesMustChangeBeforeEof(): void
    {
        $ready = "client 100.0 ready status=200 connection=keep-alive\nclient 101.0 ready status=503 connection=close\n";
        $live = "client 100.0 live status=200 connection=keep-alive draining=false\nclient 101.0 live status=200 connection=close draining=true\n";
        ShutdownAssertions::keepalive($ready, false);
        ShutdownAssertions::keepalive($live, true);
        $this->expectException(RuntimeException::class);
        ShutdownAssertions::keepalive($ready . "client 100.5 ready_eof errno=0\n", false);
    }

    public function testServiceRestartCloseFramePrecedesConnectionEnd(): void
    {
        ShutdownAssertions::websocket("client 100.0 ws_close_frame code=1012 reason=service restart\nclient 100.1 ws_closed\n");
        ShutdownAssertions::websocket("client 100.0 ws_close_frame code=1012 reason=service restart\n");
        $this->expectException(RuntimeException::class);
        ShutdownAssertions::websocket("client 99.0 ws_closed\nclient 100.0 ws_close_frame code=1012 reason=service restart\n");
    }

    public function testDeliveryOnlyRejectsExecutorsButIgnoresZombies(): void
    {
        $server = '{"server":{"settings":{"task_worker_num":0}}}';
        ShutdownAssertions::deliveryOnly($server, "Z ffmpeg --old\nS php bin/console app:serve\n");
        $this->expectException(RuntimeException::class);
        ShutdownAssertions::deliveryOnly($server, 'S /usr/bin/ffmpeg -i track');
    }

    public function testMemoryReportsKeepMeasuredLimitAndUsage(): void
    {
        $health = '{"component":"memory","status":"healthy","responseTimeMs":0.1,'
            . '"details":{"workerId":2,"usageMb":42.5,"limitMb":128,"other":0}}';
        self::assertSame(['peak' => 42.5, 'limit' => 128.0], ShutdownAssertions::memory($health));
        self::assertNull(ShutdownAssertions::memory('{"component":"database"}'));
    }

    public function testFailedContainerStartCleansOnlyOwnedResourcesAndRetainsFailureStatus(): void
    {
        $directory = sys_get_temp_dir() . '/web-shutdown-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        symlink(__DIR__ . '/Fixtures/web-shutdown-docker.php', $directory . '/docker');
        $trace = $directory . '/trace';
        $runner = new ProcessRunner();
        try {
            $result = $runner->run(new ProcessSpec(
                argv: [PHP_BINARY, dirname(__DIR__, 2) . '/test-web-shutdown-container.php'],
                environment: array_replace(getenv(), ['PATH' => $directory . ':' . getenv('PATH'), 'WEB_WORKFLOW_TRACE' => $trace]),
                timeout: 10,
                processGroup: true,
            ));
            self::assertSame(42, $result->exitCode);
            self::assertStringContainsString('injected client start failure', $result->stderr);
            $commands = file($trace, FILE_IGNORE_NEW_LINES)
                |> (static fn(array $lines): array => array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
            $network = array_values(array_filter($commands, static fn(array $command): bool => in_array('--internal', $command, true)))[0];
            $id = $network[array_key_last($network)];
            $removals = array_values(array_filter($commands, static fn(array $command): bool => in_array('-f', $command, true)));
            self::assertCount(1, $removals);
            self::assertSame(
                [
                    '--context',
                    'web-fixture',
                    'rm',
                    '-f',
                    $id . '-web',
                    $id . '-probe',
                    $id . '-client',
                    $id . '-redis',
                    $id . '-postgres',
                ],
                $removals[0],
            );
            self::assertContains(['--context', 'web-fixture', 'network', 'rm', $id], $commands);
        } finally {
            foreach (glob($directory . '/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function testCancellationStopsPendingCommandAndStillRemovesOwnedContainers(): void
    {
        $directory = sys_get_temp_dir() . '/web-shutdown-interrupt-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        symlink(__DIR__ . '/Fixtures/web-shutdown-docker.php', $directory . '/docker');
        $trace = $directory . '/trace';
        $ready = $directory . '/ready';
        $runner = new ProcessRunner();
        $process = $runner->start(new ProcessSpec(
            argv: [PHP_BINARY, dirname(__DIR__, 2) . '/test-web-shutdown-container.php'],
            environment: array_replace(
                getenv(),
                [
                    'PATH' => $directory . ':' . getenv('PATH'),
                    'DOCKER_HOST' => '',
                    'DOCKER_CONTEXT' => '',
                    'WEB_WORKFLOW_TRACE' => $trace,
                    'WEB_WORKFLOW_HANG' => '1',
                    'WEB_WORKFLOW_READY' => $ready,
                ],
            ),
            timeout: 20,
            processGroup: true,
            terminationGrace: 10,
        ));
        try {
            $until = hrtime(true) / 1e9 + 5;
            do {
                clearstatcache(true, $ready);
                if (is_file($ready)) {
                    break;
                }
                usleep(10000);
            } while (hrtime(true) / 1e9 < $until);
            self::assertFileExists($ready);
            $process->cancel(15);
            $result = $runner->wait($process);
            self::assertSame(143, $result->exitCode);
            $commands = file($trace, FILE_IGNORE_NEW_LINES)
                |> (static fn(array $lines): array => array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
            self::assertTrue(array_any($commands, static fn(array $command): bool => array_slice($command, 2, 2) === ['rm', '-f']));
            self::assertTrue(array_any($commands, static fn(array $command): bool => array_slice($command, 2, 2) === ['network', 'rm']));
        } finally {
            if ($process->result() === null) {
                $process->cancel(9);
                $runner->wait($process);
            }
            foreach (glob($directory . '/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    /** @return iterable<string,array{bool}> */
    public static function checkoutModes(): iterable
    {
        yield 'current checkout' => [false];
        yield 'baked checkout' => [true];
    }

    #[DataProvider('checkoutModes')]
    public function testCheckoutSelectionKeepsRequiredFixturesAndDoesNotRunStoppedProbeCommands(bool $baked): void
    {
        $directory = sys_get_temp_dir() . '/web-shutdown-stage-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        symlink(__DIR__ . '/Fixtures/web-shutdown-docker.php', $directory . '/docker');
        symlink(__DIR__ . '/Fixtures/web-shutdown-tar.php', $directory . '/tar');
        $trace = $directory . '/docker-trace';
        $tarTrace = $directory . '/tar-trace';
        $runner = new ProcessRunner();
        try {
            $result = $runner->run(new ProcessSpec(
                argv: [PHP_BINARY, dirname(__DIR__, 2) . '/test-web-shutdown-container.php'],
                environment: array_replace(
                    getenv(),
                    [
                        'PATH' => $directory . ':' . getenv('PATH'),
                        'DOCKER_HOST' => '',
                        'DOCKER_CONTEXT' => '',
                        'WEB_WORKFLOW_TRACE' => $trace,
                        'WEB_WORKFLOW_TAR_TRACE' => $tarTrace,
                        'WEB_WORKFLOW_STAGE' => '1',
                        'BAANDER_TEST_CHECKOUT_IN_IMAGE' => $baked ? '1' : '0',
                    ],
                ),
                timeout: 20,
                processGroup: true,
            ));
            self::assertSame(43, $result->exitCode);
            $archives = file($tarTrace, FILE_IGNORE_NEW_LINES)
                |> (static fn(array $lines): array => array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
            self::assertCount($baked ? 1 : 2, $archives);
            self::assertSame(['tests/Fixtures/WebShutdown'], array_slice($archives[array_key_last($archives)], 2));
            if (!$baked) {
                self::assertSame(
                    [
                        'vendor',
                        'src',
                        'tests/Fixtures/WebShutdown',
                        'tests/Fixtures/WebRuntime',
                        'tests/Fixtures/Auth',
                        'tests/Fixtures/web-runtime.php',
                        'config',
                        'packages',
                        'migrations',
                        'bin',
                        'docker/general',
                        'templates',
                        'public',
                        '.env',
                        'composer.json',
                        'composer.lock',
                        'translations',
                    ],
                    array_slice($archives[0], 2),
                );
            }
            $commands = file($trace, FILE_IGNORE_NEW_LINES)
                |> (static fn(array $lines): array => array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines));
            foreach ($commands as $command) {
                if (($command[2] ?? '') === 'exec') {
                    self::assertFalse(array_any($command, static fn(string $argument): bool => str_ends_with($argument, '-probe')));
                }
            }
        } finally {
            foreach (glob($directory . '/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
