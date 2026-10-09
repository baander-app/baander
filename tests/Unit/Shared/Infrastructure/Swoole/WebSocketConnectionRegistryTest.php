<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketRegistrationRefused;
use PHPUnit\Framework\TestCase;
use Swoole\Table;

final class WebSocketConnectionRegistryTest extends TestCase
{
    private WebSocketConnectionRegistry $registry;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded.');
        }

        $this->registry = WebSocketConnectionRegistry::create(
            maxConnections: 64,
            maxRoomMembers: 256,
        );
    }

    // --- Connection lifecycle ---

    public function testAddConnectionStoresConnection(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);

        $conn = $this->registry->getConnection(1);

        $this->assertNotNull($conn);
        $this->assertSame('user-uuid-1', $conn['user_id']);
        $this->assertSame(0, $conn['worker_id']);
        $this->assertGreaterThan(0, $conn['connected_at']);
    }

    public function testAddConnectionStoresCurrentTimestamp(): void
    {
        $before = time();
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $after = time();

        $conn = $this->registry->getConnection(1);

        $this->assertNotNull($conn);
        $this->assertGreaterThanOrEqual($before, $conn['connected_at']);
        $this->assertLessThanOrEqual($after, $conn['connected_at']);
    }

    public function testRemoveConnectionDeletesFromTable(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->removeConnection(1);

        $this->assertNull($this->registry->getConnection(1));
    }

    public function testRemoveNonExistentConnectionDoesNotThrow(): void
    {
        $this->registry->removeConnection(999);

        $this->assertNull($this->registry->getConnection(999));
    }

    public function testGetConnectionReturnsNullForMissingFd(): void
    {
        $this->assertNull($this->registry->getConnection(42));
    }

    public function testGetAllConnectionsReturnsAllEntries(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->addConnection(2, 'user-uuid-2', 1);

        $all = $this->registry->getAllConnections();

        $this->assertCount(2, $all);
        $userIds = array_column($all, 'user_id');
        $this->assertContains('user-uuid-1', $userIds);
        $this->assertContains('user-uuid-2', $userIds);
    }

    // --- Per-user connection limit ---

    public function testAddConnectionRejectsBeyondMaxPerUser(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->registry->addConnection($i, 'user-uuid-1', 0);
        }

        $this->expectException(WebSocketRegistrationRefused::class);
        $this->expectExceptionMessage('has reached the maximum of 10 WebSocket connections');

        $this->registry->addConnection(11, 'user-uuid-1', 0);
    }

    public function testDifferentUsersCanEachHaveMaxConnections(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->registry->addConnection($i, 'user-uuid-1', 0);
        }
        for ($i = 11; $i <= 20; ++$i) {
            $this->registry->addConnection($i, 'user-uuid-2', 0);
        }

        $this->assertCount(10, $this->registry->getUserConnectionFds('user-uuid-1'));
        $this->assertCount(10, $this->registry->getUserConnectionFds('user-uuid-2'));
    }

    public function testRemovedConnectionSlotFreesUpForSameUser(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->registry->addConnection($i, 'user-uuid-1', 0);
        }
        $this->registry->removeConnection(1);

        // Should not throw since one slot freed up
        $this->registry->addConnection(11, 'user-uuid-1', 0);

        $fds = $this->registry->getUserConnectionFds('user-uuid-1');
        $this->assertCount(10, $fds);
        $this->assertContains(11, $fds);
    }

    // --- getUserConnectionFds ---

    public function testGetUserConnectionFdsReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->registry->getUserConnectionFds('unknown-user'));
    }

    public function testGetUserConnectionFdsReturnsOnlyMatchingFds(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->addConnection(2, 'user-uuid-2', 0);
        $this->registry->addConnection(3, 'user-uuid-1', 1);

        $fds = $this->registry->getUserConnectionFds('user-uuid-1');

        $this->assertCount(2, $fds);
        $this->assertContains(1, $fds);
        $this->assertContains(3, $fds);
        $this->assertNotContains(2, $fds);
    }

    // --- Room membership ---

    public function testJoinRoomAddsToBothTables(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->joinRoom('room:123', 1);

        $members = $this->registry->getRoomMembers('room:123');

        $this->assertSame([1], $members);
    }

    public function testJoinRoomIsIdempotent(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->joinRoom('room:123', 1);
        $this->registry->joinRoom('room:123', 1);

        $members = $this->registry->getRoomMembers('room:123');

        // Swoole Table set() overwrites, so there is only one entry
        $this->assertCount(1, $members);
        $this->assertSame([1], $members);
    }

    public function testLeaveRoomRemovesFromBothTables(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->joinRoom('room:123', 1);
        $this->registry->leaveRoom('room:123', 1);

        $this->assertSame([], $this->registry->getRoomMembers('room:123'));
    }

    public function testLeaveRoomNotJoinedDoesNotThrow(): void
    {
        $this->registry->leaveRoom('room:unknown', 999);
        $this->assertSame([], $this->registry->getRoomMembers('room:unknown'));
    }

    public function testGetRoomMembersReturnsAllFdsInRoom(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->addConnection(2, 'user-uuid-2', 0);
        $this->registry->addConnection(3, 'user-uuid-3', 1);
        $this->registry->joinRoom('room:123', 1);
        $this->registry->joinRoom('room:123', 2);
        $this->registry->joinRoom('room:123', 3);

        $members = $this->registry->getRoomMembers('room:123');

        $this->assertCount(3, $members);
        $this->assertContains(1, $members);
        $this->assertContains(2, $members);
        $this->assertContains(3, $members);
    }

    public function testGetRoomMembersDoesNotReturnFdsFromOtherRooms(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->addConnection(2, 'user-uuid-2', 0);
        $this->registry->joinRoom('room:aaa', 1);
        $this->registry->joinRoom('room:bbb', 2);

        $membersAaa = $this->registry->getRoomMembers('room:aaa');
        $membersBbb = $this->registry->getRoomMembers('room:bbb');

        $this->assertSame([1], $membersAaa);
        $this->assertSame([2], $membersBbb);
    }

    public function testGetRoomMembersReturnsEmptyForUnknownRoom(): void
    {
        $this->assertSame([], $this->registry->getRoomMembers('room:unknown'));
    }

    // --- leaveAllRooms (reverse index via fdRooms) ---

    public function testLeaveAllRoomsRemovesFdFromEveryRoom(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->joinRoom('room:aaa', 1);
        $this->registry->joinRoom('room:bbb', 1);
        $this->registry->joinRoom('room:ccc', 1);

        $this->registry->leaveAllRooms(1);

        $this->assertSame([], $this->registry->getRoomMembers('room:aaa'));
        $this->assertSame([], $this->registry->getRoomMembers('room:bbb'));
        $this->assertSame([], $this->registry->getRoomMembers('room:ccc'));
    }

    public function testLeaveAllRoomsDoesNotAffectOtherFds(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->addConnection(2, 'user-uuid-2', 0);
        $this->registry->joinRoom('room:123', 1);
        $this->registry->joinRoom('room:123', 2);

        $this->registry->leaveAllRooms(1);

        // FD 2 should still be in the room
        $members = $this->registry->getRoomMembers('room:123');
        $this->assertSame([2], $members);
    }

    public function testLeaveAllRoomsOnNonExistentFdDoesNotThrow(): void
    {
        $this->registry->leaveAllRooms(999);

        // No exception means success — assert no ghost entries were created
        $this->assertSame([], $this->registry->getRoomMembers('room:999'));
    }

    // --- removeConnection also cleans up rooms ---

    public function testRemoveConnectionAlsoCleansUpRoomMemberships(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->joinRoom('room:123', 1);

        $this->registry->removeConnection(1);

        $this->assertNull($this->registry->getConnection(1));
        $this->assertSame([], $this->registry->getRoomMembers('room:123'));
    }

    // --- cleanupOrphans ---

    public function testCleanupOrphansRemovesDeadConnections(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->addConnection(2, 'user-uuid-2', 0);
        $this->registry->joinRoom('room:123', 1);

        $isEstablished = fn (int $fd): bool => $fd !== 1;

        $count = $this->registry->cleanupOrphans($isEstablished);

        $this->assertSame(1, $count);
        $this->assertNull($this->registry->getConnection(1));
        $this->assertNotNull($this->registry->getConnection(2));
        $this->assertSame([], $this->registry->getRoomMembers('room:123'));
    }

    public function testCleanupOrphansReturnsZeroWhenAllAlive(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->addConnection(2, 'user-uuid-2', 0);

        $isEstablished = fn (int $fd): bool => true;

        $count = $this->registry->cleanupOrphans($isEstablished);

        $this->assertSame(0, $count);
        $this->assertNotNull($this->registry->getConnection(1));
        $this->assertNotNull($this->registry->getConnection(2));
    }

    public function testCleanupOrphansReturnsZeroOnEmptyTable(): void
    {
        $isEstablished = fn (int $fd): bool => false;

        $count = $this->registry->cleanupOrphans($isEstablished);

        $this->assertSame(0, $count);
    }

    // --- FD can be reused after removal ---

    public function testFdCanBeReusedAfterRemoval(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->removeConnection(1);
        $this->registry->addConnection(1, 'user-uuid-2', 0);

        $conn = $this->registry->getConnection(1);

        $this->assertNotNull($conn);
        $this->assertSame('user-uuid-2', $conn['user_id']);
    }

    // --- Same FD can join multiple rooms ---

    public function testSameFdCanJoinMultipleRooms(): void
    {
        $this->registry->addConnection(1, 'user-uuid-1', 0);
        $this->registry->joinRoom('room:aaa', 1);
        $this->registry->joinRoom('room:bbb', 1);
        $this->registry->joinRoom('room:ccc', 1);

        $this->assertSame([1], $this->registry->getRoomMembers('room:aaa'));
        $this->assertSame([1], $this->registry->getRoomMembers('room:bbb'));
        $this->assertSame([1], $this->registry->getRoomMembers('room:ccc'));

        $this->registry->leaveAllRooms(1);

        $this->assertSame([], $this->registry->getRoomMembers('room:aaa'));
        $this->assertSame([], $this->registry->getRoomMembers('room:bbb'));
        $this->assertSame([], $this->registry->getRoomMembers('room:ccc'));
    }

    // --- Capacity ---

    public function testTheDefaultRegistryHoldsItsStatedConnectionAndMembershipLimits(): void
    {
        $registry = WebSocketConnectionRegistry::create();
        $rooms = [];
        for ($room = 0; $room < 8; ++$room) {
            $rooms[] = sprintf('party:01900000-0000-7000-8000-%012d', $room);
        }

        for ($fd = 1; $fd <= 1024; ++$fd) {
            $registry->addConnection($fd, sprintf('01900000-0000-7000-9000-%012d', $fd), 0);
            foreach ($rooms as $room) {
                $registry->joinRoom($room, $fd);
            }
        }

        self::assertCount(1024, $registry->getAllConnections());
        self::assertSame('01900000-0000-7000-9000-000000001024', $registry->getConnection(1024)['user_id'] ?? null);
        foreach ($rooms as $room) {
            self::assertCount(1024, $registry->getRoomMembers($room));
        }
    }

    public function testAConnectionTheTableCannotHoldIsRefusedAndNotRegistered(): void
    {
        $registry = WebSocketConnectionRegistry::create(maxConnections: 1, maxRoomMembers: 1);

        $refused = null;
        for ($fd = 1; $fd <= 10_000 && $refused === null; ++$fd) {
            try {
                $registry->addConnection($fd, sprintf('user-%d', $fd), 0);
            } catch (WebSocketRegistrationRefused $error) {
                $refused = $fd;
                self::assertSame(WebSocketRegistrationRefused::CLOSE_TRY_AGAIN_LATER, $error->closeCode);
            }
        }

        self::assertNotNull($refused, 'A full connection table accepted every connection.');
        self::assertNull($registry->getConnection($refused));
        self::assertCount($refused - 1, $registry->getAllConnections());
    }

    public function testAMembershipTheTablesCannotHoldIsRefusedWithoutAHalfMembership(): void
    {
        $registry = WebSocketConnectionRegistry::create(maxConnections: 1, maxRoomMembers: 1);
        $registry->addConnection(1, 'user-1', 0);
        // Fill only the per-connection index, so the first of the two writes succeeds
        // and the second is refused.
        $fdRooms = (new \ReflectionProperty($registry, 'fdRooms'))->getValue($registry);
        self::assertInstanceOf(Table::class, $fdRooms);
        self::fillTable($fdRooms, ['room_name' => 'filler']);

        try {
            $registry->joinRoom('room:full', 1);
            self::fail('A membership the index could not hold was accepted.');
        } catch (WebSocketRegistrationRefused) {
        }

        self::assertSame([], $registry->getRoomMembers('room:full'));
    }

    public function testARoomNameTooLongForTheTableKeysIsRejected(): void
    {
        $tooLong = str_repeat('r', WebSocketConnectionRegistry::MAX_ROOM_NAME_BYTES + 1);
        try {
            $this->registry->joinRoom($tooLong, 1);
            self::fail('A room name too long for the table keys was accepted.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $this->registry->getRoomMembers($tooLong));

        // The longest name with the widest fd still round-trips through the key scans.
        $longest = str_repeat('r', WebSocketConnectionRegistry::MAX_ROOM_NAME_BYTES);
        $this->registry->joinRoom($longest, 2_147_483_647);
        self::assertSame([2_147_483_647], $this->registry->getRoomMembers($longest));
        $this->registry->leaveAllRooms(2_147_483_647);
        self::assertSame([], $this->registry->getRoomMembers($longest));
    }

    /**
     * Writes filler rows until a thousand writes in a row are refused. The first
     * refusal alone does not mean the table is full: a key whose bucket is still free
     * would get a row.
     *
     * @param array<string, int|string> $row
     */
    private static function fillTable(Table $table, array $row): void
    {
        $refusedInARow = 0;
        for ($key = 0; $refusedInARow < 1000; ++$key) {
            self::assertLessThan(100_000, $key, 'The table never filled up.');
            $refusedInARow = @$table->set('filler-' . $key, $row) ? 0 : $refusedInARow + 1;
        }
    }
}
