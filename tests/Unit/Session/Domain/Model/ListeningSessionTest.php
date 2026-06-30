<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain\Model;

use App\Session\Domain\Event\SessionClaimed;
use App\Session\Domain\Event\SessionCreated;
use App\Session\Domain\Event\SessionUpdated;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ListeningSessionTest extends TestCase
{
    private Uuid $userId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v4();
    }

    public function testCreateWithQueueAndPosition(): void
    {
        $queue = ['track-a', 'track-b', 'track-c'];

        $session = ListeningSession::create(
            userId: $this->userId,
            queue: $queue,
            currentTrackIndex: 1,
            position: 45.5,
        );

        $this->assertInstanceOf(Uuid::class, $session->getId());
        $this->assertTrue($session->getUserId()->equals($this->userId));
        $this->assertNull($session->getActiveDeviceId());
        $this->assertSame($queue, $session->getQueue());
        $this->assertSame(1, $session->getCurrentTrackIndex());
        $this->assertSame(45.5, $session->getPosition());
        $this->assertSame('stopped', $session->getPlaybackState());
        $this->assertInstanceOf(DateTimeImmutable::class, $session->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $session->getUpdatedAt());
        $this->assertNull($session->getLastUsedAt());
    }

    public function testCreateFiresSessionCreatedEvent(): void
    {
        $queue = ['track-a', 'track-b'];

        $session = ListeningSession::create(
            userId: $this->userId,
            queue: $queue,
            currentTrackIndex: 0,
            position: 0.0,
        );

        $events = $session->drainPendingEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(SessionCreated::class, $events[0]);
        $this->assertTrue($events[0]->getUserId()->equals($this->userId));
        $this->assertSame($queue, $events[0]->getQueue());
    }

    public function testClaimTransfersActiveDeviceAndFiresEvent(): void
    {
        $session = ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 0.0,
        );

        // Drain the creation event
        $session->drainPendingEvents();

        $deviceId = Uuid::v4();
        $session->claim($deviceId);

        $this->assertTrue($session->getActiveDeviceId()->equals($deviceId));

        $events = $session->drainPendingEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(SessionClaimed::class, $events[0]);
        $this->assertTrue($events[0]->getDeviceId()->equals($deviceId));
        $this->assertTrue($events[0]->getUserId()->equals($this->userId));
    }

    public function testClaimFromSameDeviceIsNoOp(): void
    {
        $session = ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 0.0,
        );

        $deviceId = Uuid::v4();
        $session->claim($deviceId);
        $session->drainPendingEvents();

        $updatedAtBefore = $session->getUpdatedAt();

        // Claim again with the same device
        $session->claim($deviceId);

        $this->assertTrue($session->getActiveDeviceId()->equals($deviceId));
        $events = $session->drainPendingEvents();
        $this->assertCount(0, $events);
    }

    public function testClaimDifferentDeviceTransfersAndFiresEvent(): void
    {
        $session = ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 0.0,
        );

        $device1 = Uuid::v4();
        $device2 = Uuid::v4();

        $session->claim($device1);
        $session->drainPendingEvents();

        $session->claim($device2);

        $this->assertTrue($session->getActiveDeviceId()->equals($device2));
        $events = $session->drainPendingEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(SessionClaimed::class, $events[0]);
        $this->assertTrue($events[0]->getDeviceId()->equals($device2));
    }

    public function testUpdatePlaybackModifiesPositionAndFiresEvent(): void
    {
        $session = ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a', 'track-b'],
            currentTrackIndex: 0,
            position: 0.0,
        );

        $session->drainPendingEvents();

        $newQueue = ['track-a', 'track-b', 'track-c'];
        $session->updatePlayback(
            queue: $newQueue,
            currentTrackIndex: 2,
            position: 120.5,
            playbackState: 'playing',
        );

        $this->assertSame($newQueue, $session->getQueue());
        $this->assertSame(2, $session->getCurrentTrackIndex());
        $this->assertSame(120.5, $session->getPosition());
        $this->assertSame('playing', $session->getPlaybackState());

        $events = $session->drainPendingEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(SessionUpdated::class, $events[0]);
        $this->assertSame($newQueue, $events[0]->getQueue());
    }

    public function testUpdatePlaybackThrowsOnNegativeTrackIndex(): void
    {
        $session = ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 0.0,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Current track index cannot be negative.');

        $session->updatePlayback(
            queue: ['track-a'],
            currentTrackIndex: -1,
            position: 0.0,
            playbackState: 'playing',
        );
    }

    public function testUpdatePlaybackThrowsOnNegativePosition(): void
    {
        $session = ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 0.0,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position cannot be negative.');

        $session->updatePlayback(
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: -5.0,
            playbackState: 'playing',
        );
    }

    public function testUpdatePlaybackThrowsOnInvalidState(): void
    {
        $session = ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 0.0,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid playback state "rewinding"');

        $session->updatePlayback(
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 0.0,
            playbackState: 'rewinding',
        );
    }

    public function testEndSetsPlaybackStateToStopped(): void
    {
        $session = ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 30.0,
        );

        $session->updatePlayback(
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 30.0,
            playbackState: 'playing',
        );

        $this->assertSame('playing', $session->getPlaybackState());

        $session->end();

        $this->assertSame('stopped', $session->getPlaybackState());
    }

    public function testCreateThrowsOnNegativeTrackIndex(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Current track index cannot be negative.');

        ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: -1,
            position: 0.0,
        );
    }

    public function testCreateThrowsOnNegativePosition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position cannot be negative.');

        ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: -10.0,
        );
    }

    public function testMarkUsedUpdatesTimestamps(): void
    {
        $session = ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 0.0,
        );

        $this->assertNull($session->getLastUsedAt());

        $before = new DateTimeImmutable();
        $session->markUsed();
        $after = new DateTimeImmutable();

        $this->assertNotNull($session->getLastUsedAt());
        $this->assertGreaterThanOrEqual($before, $session->getLastUsedAt());
        $this->assertLessThanOrEqual($after, $session->getLastUsedAt());
        $this->assertGreaterThanOrEqual($before, $session->getUpdatedAt());
        $this->assertLessThanOrEqual($after, $session->getUpdatedAt());
    }

    public function testDrainPendingEventsClearsEvents(): void
    {
        $session = ListeningSession::create(
            userId: $this->userId,
            queue: ['track-a'],
            currentTrackIndex: 0,
            position: 0.0,
        );

        $this->assertCount(1, $session->drainPendingEvents());
        $this->assertCount(0, $session->drainPendingEvents());
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $id = Uuid::v4();
        $deviceId = Uuid::v4();
        $createdAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $updatedAt = new DateTimeImmutable('2025-06-01 12:00:00');
        $lastUsedAt = new DateTimeImmutable('2025-06-01 11:30:00');
        $queue = ['track-x', 'track-y'];

        $state = new \App\Session\Domain\Model\ListeningSession\ListeningSessionState(
            id: $id,
            userId: $this->userId,
            activeDeviceId: $deviceId,
            queue: $queue,
            currentTrackIndex: 1,
            position: 99.9,
            playbackState: 'paused',
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            lastUsedAt: $lastUsedAt,
        );

        $session = ListeningSession::reconstitute($state);

        $this->assertTrue($session->getId()->equals($id));
        $this->assertTrue($session->getUserId()->equals($this->userId));
        $this->assertTrue($session->getActiveDeviceId()->equals($deviceId));
        $this->assertSame($queue, $session->getQueue());
        $this->assertSame(1, $session->getCurrentTrackIndex());
        $this->assertSame(99.9, $session->getPosition());
        $this->assertSame('paused', $session->getPlaybackState());
        $this->assertEquals($createdAt, $session->getCreatedAt());
        $this->assertEquals($updatedAt, $session->getUpdatedAt());
        $this->assertEquals($lastUsedAt, $session->getLastUsedAt());
    }
}
