<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use Swoole\Table;

/**
 * Connections and room memberships of every worker, in Swoole tables shared across
 * the forked workers.
 *
 * Swoole sends a key whose hash bucket is taken to an overflow pool of
 * size * conflict proportion rows. With the default proportion (0.2) the pool runs
 * out long before the table holds `size` keys: measured on Swoole 6.2.1, a
 * 1,024-row connection table refused connection 455, and an 8,192-row membership
 * table refused membership 5,872. A proportion of 1.0 makes the overflow pool as
 * large as the table, so the stated limits fit whatever the keys hash to. That
 * costs about 2.5 MB of shared memory at the default sizes.
 *
 * A write the tables refuse is reported with WebSocketRegistrationRefused, after
 * removing anything already written for it, so no half-registered connection or
 * membership is left behind.
 */
final class WebSocketConnectionRegistry
{
    private const int MAX_CONNECTIONS_PER_USER = 10;
    private const float CONFLICT_PROPORTION = 1.0;
    /**
     * Swoole stores at most 63 bytes of a key and cuts a longer one short, so the
     * prefix scans below would miss it. A membership key is the room name, a NUL and
     * the fd (at most 10 digits), which leaves 52 bytes for the name.
     */
    public const int MAX_ROOM_NAME_BYTES = 52;

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
        $connections = new Table($maxConnections, self::CONFLICT_PROPORTION);
        $connections->column('user_id', Table::TYPE_STRING, 36);
        $connections->column('worker_id', Table::TYPE_INT);
        $connections->column('connected_at', Table::TYPE_INT);
        $connections->create();

        $roomMembers = new Table($maxRoomMembers, self::CONFLICT_PROPORTION);
        $roomMembers->column('joined_at', Table::TYPE_INT);
        $roomMembers->create();

        $fdRooms = new Table($maxRoomMembers, self::CONFLICT_PROPORTION);
        $fdRooms->column('room_name', Table::TYPE_STRING, self::MAX_ROOM_NAME_BYTES);
        $fdRooms->create();

        return new self($connections, $roomMembers, $fdRooms);
    }

    /**
     * @throws WebSocketRegistrationRefused when the user has too many connections or the
     *         table has no free row; the connection is then not registered
     */
    public function addConnection(int $fd, string $userId, int $workerId): void
    {
        $existingCount = $this->countUserConnections($userId);
        if ($existingCount >= self::MAX_CONNECTIONS_PER_USER) {
            throw WebSocketRegistrationRefused::userConnectionLimit($userId, self::MAX_CONNECTIONS_PER_USER);
        }

        // A full table makes set() warn and return false; the refusal is reported below.
        $stored = @$this->connections->set((string) $fd, [
            'user_id' => $userId,
            'worker_id' => $workerId,
            'connected_at' => time(),
        ]);
        if (!$stored) {
            throw WebSocketRegistrationRefused::connectionTableFull($fd);
        }
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

    /**
     * @throws \InvalidArgumentException when the room name is longer than MAX_ROOM_NAME_BYTES
     * @throws WebSocketRegistrationRefused when the tables have no free row; the
     *         connection is then not a member
     */
    public function joinRoom(string $room, int $fd): void
    {
        // getRoomMembers() and leaveAllRooms() find memberships by key prefix. A key
        // cut short would hide the membership and leave it behind for a reused fd.
        if (strlen($room) > self::MAX_ROOM_NAME_BYTES) {
            throw new \InvalidArgumentException(sprintf('A room name may be at most %d bytes long.', self::MAX_ROOM_NAME_BYTES));
        }

        $memberKey = $room . "\0" . $fd;
        // A full table makes set() warn and return false; the refusal is reported below.
        if (!@$this->roomMembers->set($memberKey, ['joined_at' => time()])) {
            throw WebSocketRegistrationRefused::roomTableFull($room, $fd);
        }
        if (!@$this->fdRooms->set($fd . "\0" . $room, ['room_name' => $room])) {
            // Without its index row, leaveAllRooms() could never remove the membership.
            $this->roomMembers->del($memberKey);

            throw WebSocketRegistrationRefused::roomTableFull($room, $fd);
        }
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

    /** @return array{user_id: string, worker_id: int, connected_at: int}|null */
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
