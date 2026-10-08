<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control;

use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Infrastructure\Swoole\Control\ServerControl;
use App\Shared\Infrastructure\Swoole\Control\ServerControlCoordinator;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperationRegistry;
use App\Shared\Infrastructure\Swoole\Control\SocketServerControlClient;
use App\Shared\Infrastructure\Swoole\Control\SwooleServerWorkers;
use PHPUnit\Framework\TestCase;

final class SocketServerControlClientTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-control-client-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testNoSocketMeansNoServerIsRunning(): void
    {
        $this->expectException(ServerNotRunningException::class);
        $this->expectExceptionMessage('no web server is running in this container');

        (new SocketServerControlClient($this->directory . '/control.sock'))->execute('debug.stats');
    }

    public function testStaleSocketFileMeansNoServerIsRunning(): void
    {
        $path = $this->directory . '/control.sock';
        $stale = stream_socket_server('unix://' . $path);
        self::assertIsResource($stale);
        fclose($stale);

        $this->expectException(ServerNotRunningException::class);
        $this->expectExceptionMessage('no web server is running in this container');

        (new SocketServerControlClient($path))->execute('debug.stats');
    }

    public function testServerThatDoesNotAnswerTimesOut(): void
    {
        $path = $this->directory . '/control.sock';
        $listening = stream_socket_server('unix://' . $path);
        self::assertIsResource($listening);

        try {
            $this->expectException(ServerControlException::class);
            $this->expectExceptionMessage('the web server did not answer within 0.2 seconds');

            (new SocketServerControlClient($path, 0.2))->execute('debug.stats');
        } finally {
            fclose($listening);
        }
    }

    public function testOutsideAnHttpWorkerThePortUsesTheSocket(): void
    {
        // No server is attached in a console process.
        $port = new ServerControl(
            new ServerControlCoordinator(new ServerControlOperationRegistry([]), new SwooleServerWorkers()),
            new SocketServerControlClient($this->directory . '/control.sock'),
        );

        $this->expectException(ServerNotRunningException::class);

        $port->execute('debug.stats');
    }
}
