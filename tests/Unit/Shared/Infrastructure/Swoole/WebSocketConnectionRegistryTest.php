<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use PHPUnit\Framework\TestCase;

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
        $this->assertIsInt($conn['connected_at']);
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

        $this->expectException(\RuntimeException::class);
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
}
