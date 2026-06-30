<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Domain\Model\RadioSession;

use App\Radio\Domain\Model\RadioSession\RadioSession;
use App\Radio\Domain\Model\RadioSession\RadioSessionState;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class RadioSessionTest extends TestCase
{
    private Uuid $userId;
    private Uuid $stationId;

    protected function setUp(): void
    {
        $this->userId = Uuid::v7();
        $this->stationId = Uuid::v7();
    }

    public function testCreateIsStopped(): void
    {
        $session = RadioSession::create(userId: $this->userId);

        $this->assertInstanceOf(Uuid::class, $session->getId());
        $this->assertTrue($session->getUserId()->equals($this->userId));
        $this->assertSame('stopped', $session->getState());
        $this->assertNull($session->getActiveStationId());
        $this->assertNull($session->getActiveStreamUrl());
        $this->assertInstanceOf(DateTimeImmutable::class, $session->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $session->getUpdatedAt());
    }

    public function testStartPlayingWithStationAndStream(): void
    {
        $session = RadioSession::create(userId: $this->userId);

        $streamUrl = 'https://stream.example.com/high';
        $session->start(stationId: $this->stationId, streamUrl: $streamUrl);

        $this->assertSame('playing', $session->getState());
        $this->assertTrue($session->getActiveStationId()->equals($this->stationId));
        $this->assertSame($streamUrl, $session->getActiveStreamUrl());
    }

    public function testStopPlaying(): void
    {
        $session = RadioSession::create(userId: $this->userId);
        $session->start(stationId: $this->stationId, streamUrl: 'https://stream.example.com');

        $this->assertSame('playing', $session->getState());

        $session->stop();

        $this->assertSame('stopped', $session->getState());
        $this->assertNull($session->getActiveStationId());
        $this->assertNull($session->getActiveStreamUrl());
    }

    public function testStartFiresRadioSessionStartedEvent(): void
    {
        $session = RadioSession::create(userId: $this->userId);
        // Creation doesn't fire events for radio sessions
        $this->assertCount(0, $session->drainPendingEvents());

        $session->start(stationId: $this->stationId, streamUrl: 'https://stream.example.com');

        $events = $session->drainPendingEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\App\Radio\Domain\Event\RadioSessionStarted::class, $events[0]);
    }

    public function testStopFiresRadioSessionStoppedEvent(): void
    {
        $session = RadioSession::create(userId: $this->userId);
        $session->start(stationId: $this->stationId, streamUrl: 'https://stream.example.com');
        $session->drainPendingEvents();

        $session->stop();

        $events = $session->drainPendingEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\App\Radio\Domain\Event\RadioSessionStopped::class, $events[0]);
    }

    public function testStopWhenAlreadyStoppedThrows(): void
    {
        $session = RadioSession::create(userId: $this->userId);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot stop a session that is not playing.');

        $session->stop();
    }

    public function testStartWhenAlreadyPlayingThrows(): void
    {
        $session = RadioSession::create(userId: $this->userId);
        $session->start(stationId: $this->stationId, streamUrl: 'https://stream.example.com');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot start a session that is already playing.');

        $session->start(stationId: $this->stationId, streamUrl: 'https://stream.example.com');
    }

    public function testReconstituteRoundtrip(): void
    {
        $id = Uuid::v7();
        $createdAt = new DateTimeImmutable('2025-01-01 00:00:00');
        $updatedAt = new DateTimeImmutable('2025-06-01 12:00:00');

        $state = new RadioSessionState(
            id: $id,
            userId: $this->userId,
            activeStationId: $this->stationId,
            activeStreamUrl: 'https://stream.example.com',
            state: 'playing',
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $session = RadioSession::reconstitute($state);

        $this->assertTrue($session->getId()->equals($id));
        $this->assertTrue($session->getUserId()->equals($this->userId));
        $this->assertTrue($session->getActiveStationId()->equals($this->stationId));
        $this->assertSame('https://stream.example.com', $session->getActiveStreamUrl());
        $this->assertSame('playing', $session->getState());
        $this->assertEquals($createdAt, $session->getCreatedAt());
        $this->assertEquals($updatedAt, $session->getUpdatedAt());
    }

    public function testDrainPendingEventsClearsEvents(): void
    {
        $session = RadioSession::create(userId: $this->userId);
        $session->start(stationId: $this->stationId, streamUrl: 'https://stream.example.com');

        $this->assertCount(1, $session->drainPendingEvents());
        $this->assertCount(0, $session->drainPendingEvents());
    }
}
