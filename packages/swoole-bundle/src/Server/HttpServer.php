<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Server;

use Assert\AssertionFailedException;
use Swoole\Coroutine;
use Swoole\Process;
use Swoole\Server\Port as Listener;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Metrics\MetricsProvider as SwooleMetricsProvider;
use SwooleBundle\SwooleBundle\Server\Exception\IllegalInitializationException;
use SwooleBundle\SwooleBundle\Server\Exception\NotRunningException;
use SwooleBundle\SwooleBundle\Server\Exception\PortUnavailableException;
use SwooleBundle\SwooleBundle\Server\Exception\UnexpectedPortException;
use SwooleBundle\SwooleBundle\Server\Exception\UninitializedException;
use Throwable;
use function extension_loaded;

/**
 * @phpstan-import-type SwooleMetricsShape from SwooleMetricsProvider
 */
final class HttpServer
{
    public const int GRACEFUL_SHUTDOWN_TIMEOUT_SECONDS = 10;

    /**
     * @var Server|null
     */
    private $server;

    /**
     * @var array<Listener>
     */
    private $listeners = [];
    private $signalTerminate;
    private $signalReload;
    private $signalKill;

    public function __construct(
        private readonly HttpServerConfiguration $configuration,
        private bool $running = false,
    )
    {
        $this->signalTerminate = defined('SIGTERM') ? (int)constant('SIGTERM') : 15;
        $this->signalReload = defined('SIGUSR1') ? (int)constant('SIGUSR1') : 10;
        $this->signalKill = defined('SIGKILL') ? (int)constant('SIGKILL') : 9;
    }

    /**
     * Attach already configured Swoole WebSocket Server instance.
     */
    public function attach(Server $server): void
    {
        $this->assertNotInitialized();
        $this->assertInstanceConfiguredProperly($server);

        $this->server = $server;
        $defaultSocketPort = $this->configuration->getServerSocket()
            ->port();

        foreach ($server->ports as $listener) {
            if ($listener->port === $defaultSocketPort) {
                continue;
            }

            $this->assertPortAvailable($this->listeners, $listener->port);
            $this->listeners[$listener->port] = $listener;
        }
    }

    private function assertNotInitialized(): void
    {
        if ($this->server === null) {
            return;
        }

        throw IllegalInitializationException::make();
    }

    private function assertInstanceConfiguredProperly(Server $server): void
    {
        $defaultSocket = $this->configuration->getServerSocket();

        if ($defaultSocket->port() !== $server->port) {
            throw UnexpectedPortException::with($server->port, $defaultSocket->port());
        }
    }

    /**
     * @param array<Listener> $listeners
     */
    private function assertPortAvailable(array $listeners, int $port): void
    {
        if (array_key_exists($port, $listeners) === false) {
            return;
        }

        throw PortUnavailableException::fortPort($port);
    }

    public function start(): bool
    {
        return $this->running = $this->getServer()->start();
    }

    public function getServer(): Server
    {
        if ($this->server === null) {
            throw UninitializedException::make();
        }

        return $this->server;
    }

    /**
     * @throws AssertionFailedException
     * @throws NotRunningException
     */
    public function shutdown(bool $noDelay = false): void
    {
        if ($this->server instanceof Server) {
            $this->server->shutdown();
        } else {
            if ($this->isRunningInBackground()) {
                if ($noDelay) {
                    $this->immediateSignalShutdown($this->configuration->getPid());
                } else {
                    $this->gracefulSignalShutdown($this->configuration->getPid(), self::GRACEFUL_SHUTDOWN_TIMEOUT_SECONDS);
                }
            } else {
                throw NotRunningException::make();
            }
        }
    }

    private function isRunningInBackground(): bool
    {
        try {
            return Process::kill($this->configuration->getPid(), 0);
        } catch (Throwable) {
            return false;
        }
    }

    private function immediateSignalShutdown(int $masterPid): void
    {
        Process::kill($masterPid, $this->signalKill);
    }

    private function gracefulSignalShutdown(int $masterPid, float $timeoutSeconds): void
    {
        Process::kill($masterPid, $this->signalTerminate);

        $start = $now = microtime(true);
        $max = $start + $timeoutSeconds;
        while ($this->isRunningInBackground() && $now < $max) {
            $now = microtime(true);
            self::sleep(1000);
        }

        if (!$this->isRunningInBackground()) {
            return;
        }

        Process::kill($masterPid, $this->signalKill);
    }

    public static function sleep(float $seconds): void
    {
        if (self::inCoroutine()) {
            Coroutine::sleep($seconds);
        } else {
            usleep((int)($seconds * 1_000_000));
        }
    }

    public static function inCoroutine(): bool
    {
        return extension_loaded('swoole') && Coroutine::getCid() > 0;
    }

    /**
     * @throws AssertionFailedException
     * @throws NotRunningException
     */
    public function reload(): void
    {
        if ($this->server instanceof Server) {
            $this->server->reload();
        } else {
            if ($this->isRunningInBackground()) {
                Process::kill($this->configuration->getPid(), $this->signalReload);
            } else {
                throw NotRunningException::make();
            }
        }
    }

    /**
     * @return SwooleMetricsShape
     */
    public function metrics(): array
    {
        return $this->getServer()->stats();
    }

    public function isRunning(): bool
    {
        return $this->running || $this->isRunningInBackground();
    }

    public function dispatchTask(mixed $data): bool
    {
        return $this->getServer()->task($data) !== false;
    }

    /**
     * Push data to a WebSocket connection.
     */
    public function push(int $fd, string|Frame $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = WEBSOCKET_FLAG_FIN): bool
    {
        return $this->getServer()->push($fd, $data, $opcode, $flags);
    }

    /**
     * @return array<Listener>
     */
    public function getListeners(): array
    {
        return $this->listeners;
    }
}
