<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class WebSocketPusher
{
    private ?\Swoole\WebSocket\Server $server = null;

    public function __construct(
        private readonly WebSocketConnectionRegistry $registry,
        private readonly JsonEncoder $jsonEncoder,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function setServer(\Swoole\Server $server): void
    {
        if (!$server instanceof \Swoole\WebSocket\Server) {
            throw new \InvalidArgumentException('WebSocket push requires a WebSocket server.');
        }

        $this->server = $server;
    }

    /**
     * Push a message to all connections belonging to a user.
     *
     * @param array<array-key, mixed> $payload
     * @return int Number of connections the message was sent to
     */
    public function push(string $userId, array $payload): int
    {
        $fds = $this->registry->getUserConnectionFds($userId);
        $data = $this->jsonEncoder->encode($payload, 'json');
        $sent = 0;

        foreach ($fds as $fd) {
            if ($this->doPush($fd, $data)) {
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * Push a message to a specific connection by FD.
     *
     * @param array<array-key, mixed>|string $payload
     */
    public function pushToConnection(int $fd, array|string $payload): bool
    {
        $data = is_string($payload) ? $payload : $this->jsonEncoder->encode($payload, 'json');

        return $this->doPush($fd, $data);
    }

    /**
     * Broadcast a message to all members of a room.
     *
     * @param array<array-key, mixed> $payload
     * @return int Number of connections the message was sent to
     */
    public function broadcast(string $room, array $payload): int
    {
        $fds = $this->registry->getRoomMembers($room);
        $data = $this->jsonEncoder->encode($payload, 'json');
        $sent = 0;

        foreach ($fds as $fd) {
            if ($this->doPush($fd, $data)) {
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * Closes every connection of a user with a close frame. The connection table is
     * shared by all workers and a process-mode server closes a connection from any
     * worker, so the calling worker reaches connections that other workers own.
     *
     * @return int Number of connections closed
     */
    public function disconnectUser(string $userId, int $code, string $reason): int
    {
        $server = $this->server ?? throw new \LogicException('No WebSocket server is attached to this worker.');
        $closed = 0;
        foreach ($this->registry->getUserConnectionFds($userId) as $fd) {
            if ($server->isEstablished($fd) && $server->disconnect($fd, $code, $reason)) {
                ++$closed;
            }
        }

        return $closed;
    }

    /**
     * Closes one connection with a close frame, or closes its socket when no frame
     * can be sent, so a refused connection never stays open.
     */
    public function close(int $fd, int $code, string $reason): bool
    {
        $server = $this->server ?? throw new \LogicException('No WebSocket server is attached to this worker.');

        return ($server->isEstablished($fd) && $server->disconnect($fd, $code, $reason)) || $server->close($fd);
    }

    private function doPush(int $fd, string $data): bool
    {
        if ($this->server === null || !$this->server->isEstablished($fd)) {
            $this->logger?->warning('WebSocket push failed: connection not established', ['fd' => $fd]);

            return false;
        }

        return $this->server->push($fd, $data);
    }
}
