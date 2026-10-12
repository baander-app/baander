<?php

declare(strict_types=1);

namespace App\Tests\Unit\SwooleBundle\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\Command\ServerStopCommand;
use SwooleBundle\SwooleBundle\Server\Api\ApiServerClientFactory;
use SwooleBundle\SwooleBundle\Server\Config\Socket;
use SwooleBundle\SwooleBundle\Server\Config\Sockets;

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
        // This test process stands in for a master that does not exit.
        $port = $this->startApiServer(getmypid());

        [$exitCode, $display] = $this->stop($port, shutdownTimeout: self::NO_WAIT);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString(sprintf('master PID %d) is still running', getmypid()), $display);
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

    /** @return array{int, string} the exit code and the output, on one line */
    private function stop(int $apiPort, int $shutdownTimeout): array
    {
        $sockets = new Sockets(new Socket('127.0.0.1', 9501), new Socket('127.0.0.1', $apiPort));
        $tester = new CommandTester(new ServerStopCommand(new ApiServerClientFactory($sockets), $shutdownTimeout));
        $exitCode = $tester->execute([]);

        return [$exitCode, (string) preg_replace('/\s+/', ' ', $tester->getDisplay())];
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
