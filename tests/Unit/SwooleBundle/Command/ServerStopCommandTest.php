<?php

declare(strict_types=1);

namespace App\Tests\Unit\SwooleBundle\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

/** swoole:server:stop against a stand-in API server (fake-api-server.php). */
final class ServerStopCommandTest extends TestCase
{
    /** The command waits the shutdown timeout plus 5 seconds; -5 makes it give up at once. */
    private const int NO_WAIT = -5;

    /** @var resource|null */
    private $apiServer;
    /** @var resource|null */
    private $apiServerOutput;

    protected function tearDown(): void
    {
        if (is_resource($this->apiServer)) {
            proc_terminate($this->apiServer);
            proc_close($this->apiServer);
        }
    }

    public function testAMasterThatIsItsContainersInitIsNotWaitedFor(): void
    {
        $port = $this->startApiServer(1);
        $startedAt = microtime(true);

        [$exitCode, $display] = $this->stop($port, shutdownTimeout: 30);

        self::assertSame(Command::SUCCESS, $exitCode, $display);
        self::assertStringContainsString('stop requested', $display);
        self::assertLessThan(2.0, microtime(true) - $startedAt, 'The command must not wait for PID 1, which takes it down when it exits.');
        self::assertSame('stop requested', $this->apiServerLine());
    }

    public function testTheCommandSucceedsOnceTheMasterHasExited(): void
    {
        $port = $this->startApiServer($this->exitedPid());

        [$exitCode, $display] = $this->stop($port, shutdownTimeout: 0);

        self::assertSame(Command::SUCCESS, $exitCode, $display);
        self::assertStringContainsString('shutdown successfully', $display);
        self::assertSame('stop requested', $this->apiServerLine());
    }

    public function testAMasterStillRunningAfterTheWaitFails(): void
    {
        // A sleeping child stands in for a master that does not exit. It is never PID 1, which the
        // command does not wait for, even when this test runs as a container's first process.
        $master = proc_open(['sleep', '30'], [], $pipes);
        self::assertIsResource($master);
        $masterPid = proc_get_status($master)['pid'];

        try {
            $port = $this->startApiServer($masterPid);

            [$exitCode, $display] = $this->stop($port, shutdownTimeout: self::NO_WAIT);
        } finally {
            proc_terminate($master);
            proc_close($master);
        }

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString(sprintf('master PID %d) is still running', $masterPid), $display);
    }

    public function testAnUnreachableApiServerFails(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($listener);
        $name = (string) stream_socket_get_name($listener, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($listener);

        [$exitCode, $display] = $this->stop($port, shutdownTimeout: self::NO_WAIT);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Cannot stop the server through its API server', $display);
    }

    /**
     * Runs the command in a process of its own with Xdebug off (run-server-stop.php says why).
     *
     * @return array{int, string} the exit code and the output, on one line
     */
    private function stop(int $apiPort, int $shutdownTimeout): array
    {
        $process = new Process(
            [PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__ . '/run-server-stop.php', (string) $apiPort, (string) $shutdownTimeout],
            env: ['XDEBUG_MODE' => 'off'],
            timeout: 30,
        );
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        /** @var array{exitCode: int, display: string} $result */
        $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

        return [$result['exitCode'], (string) preg_replace('/\s+/', ' ', $result['display'])];
    }

    private function startApiServer(int $masterPid): int
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fake-api-server.php', (string) $masterPid],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $this->apiServer = $process;
        $this->apiServerOutput = $pipes[1];
        $line = $this->apiServerLine();
        self::assertMatchesRegularExpression('/^port \d+$/', $line);

        return (int) substr($line, 5);
    }

    private function apiServerLine(): string
    {
        self::assertIsResource($this->apiServerOutput);

        return trim((string) fgets($this->apiServerOutput));
    }

    /** A process ID that no process holds any more. */
    private function exitedPid(): int
    {
        $process = proc_open(['true'], [], $pipes);
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        proc_close($process);
        self::assertDirectoryDoesNotExist('/proc/' . $pid);

        return $pid;
    }
}
