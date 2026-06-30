<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use Swoole\Table;

final class WebSocketConnectionRegistry
{
    private const int MAX_CONNECTIONS_PER_USER = 10;

    private function __construct(
        private readonly Table $connections,
        private readonly Table $roomMembers,
        private readonly Table $fdRooms,
        private int $workerId = 0,
    ) {}

    public function setWorkerId(int $workerId): void
    {
        $this->workerId = $workerId;
    }

    public function getWorkerId(): int
    {
        return $this->workerId;
    }

    public static function create(
        int $maxConnections = 1024,
        int $maxRoomMembers = 8192,
    ): self {
        $connections = new Table($maxConnections);
        $connections->column('user_id', Table::TYPE_STRING, 36);
        $connections->column('worker_id', Table::TYPE_INT);
        $connections->column('connected_at', Table::TYPE_INT);
        $connections->create();

        $roomMembers = new Table($maxRoomMembers);
        $roomMembers->column('joined_at', Table::TYPE_INT);
        $roomMembers->create();

        $fdRooms = new Table($maxRoomMembers);
        $fdRooms->column('room_name', Table::TYPE_STRING, 64);
        $fdRooms->create();

        return new self($connections, $roomMembers, $fdRooms);
    }

    public function addConnection(int $fd, string $userId, int $workerId): void
    {
        $existingCount = $this->countUserConnections($userId);
        if ($existingCount >= self::MAX_CONNECTIONS_PER_USER) {
            throw new \RuntimeException(
                sprintf('User %s has reached the maximum of %d WebSocket connections.', $userId, self::MAX_CONNECTIONS_PER_USER)
            );
        }

        $this->connections->set((string) $fd, [
            'user_id' => $userId,
            'worker_id' => $workerId,
            'connected_at' => time(),
        ]);
    }

    public function removeConnection(int $fd): void
    {
        $this->connections->del((string) $fd);
        $this->leaveAllRooms($fd);
    }

    /**
     * @return list<int>
     */
    public function getUserConnectionFds(string $userId): array
    {
        $fds = [];
        foreach ($this->connections as $fd => $row) {
            if ($row['user_id'] === $userId) {
                $fds[] = (int) $fd;
            }
        }

        return $fds;
    }

    public function joinRoom(string $room, int $fd): void
    {
        $this->roomMembers->set($room . "\0" . $fd, [
            'joined_at' => time(),
        ]);
        $this->fdRooms->set($fd . "\0" . $room, [
            'room_name' => $room,
        ]);
    }

    public function leaveRoom(string $room, int $fd): void
    {
        $this->roomMembers->del($room . "\0" . $fd);
        $this->fdRooms->del($fd . "\0" . $room);
    }

    public function leaveAllRooms(int $fd): void
    {
        $prefix = $fd . "\0";
        $toDelete = [];
        foreach ($this->fdRooms as $key => $row) {
            if (str_starts_with($key, $prefix)) {
                $room = $row['room_name'];
                $this->roomMembers->del($room . "\0" . $fd);
                $toDelete[] = $key;
            }
        }
        foreach ($toDelete as $key) {
            $this->fdRooms->del($key);
        }
    }

    /**
     * @return list<int>
     */
    public function getRoomMembers(string $room): array
    {
        $prefix = $room . "\0";
        $fds = [];
        foreach ($this->roomMembers as $key => $row) {
            if (str_starts_with($key, $prefix)) {
                $fds[] = (int) explode("\0", $key)[1];
            }
        }

        return $fds;
    }

    public function getConnection(int $fd): ?array
    {
        $row = $this->connections->get((string) $fd);
        if ($row === false) {
            return null;
        }

        return [
            'user_id' => $row['user_id'],
            'worker_id' => $row['worker_id'],
            'connected_at' => $row['connected_at'],
        ];
    }

    public function cleanupOrphans(callable $isEstablished): int
    {
        $toDelete = [];
        foreach ($this->connections as $fd => $row) {
            if (!($isEstablished)((int) $fd)) {
                $toDelete[] = (int) $fd;
            }
        }
        foreach ($toDelete as $fd) {
            $this->removeConnection($fd);
        }

        return count($toDelete);
    }

    private function countUserConnections(string $userId): int
    {
        $count = 0;
        foreach ($this->connections as $row) {
            if ($row['user_id'] === $userId) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<array{user_id: string, worker_id: int, connected_at: int}>
     */
    public function getAllConnections(): array
    {
        $result = [];
        foreach ($this->connections as $fd => $row) {
            $result[] = [
                'user_id' => $row['user_id'],
                'worker_id' => $row['worker_id'],
                'connected_at' => $row['connected_at'],
            ];
        }

        return $result;
    }
}
