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

    private function doPush(int $fd, string $data): bool
    {
        if ($this->server === null || !$this->server->isEstablished($fd)) {
            $this->logger?->warning('WebSocket push failed: connection not established', ['fd' => $fd]);

            return false;
        }

        return $this->server->push($fd, $data);
    }
}
