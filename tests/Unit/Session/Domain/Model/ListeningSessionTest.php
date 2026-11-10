<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Domain\Model;

use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\ObservedPlaybackState;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use DomainException;
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
            publicId: $publicId = new \App\Shared\Domain\Model\PublicId(),
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
        $this->assertTrue($session->getPublicId()->equals($publicId));
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

    public function testAConfirmedStoppedRunnerIsReleasedAtItsCursorAndTheSessionStaysStopped(): void
    {
        $session = $this->sessionWithRunner(PlaybackState::Playing);
        $session->control('stop', null, null, null, null, null);
        $session->confirmControl(false, null);
        $revision = $session->revision();
        $queue = $session->getQueue();

        $session->releaseConfirmedStoppedRunner();

        $this->assertNull($session->activeIncarnationId());
        $this->assertNull($session->selectedDestinationId());
        $this->assertSame(PlaybackState::Stopped, $session->desiredState());
        $this->assertSame(ObservedPlaybackState::Stopped, $session->observedState());
        $this->assertSame('stopped', $session->getPlaybackState());
        $this->assertSame(45.5, $session->getPosition());
        $this->assertSame($queue, $session->getQueue());
        $this->assertSame($revision + 1, $session->revision());
    }

    public function testAStopTheRunnerHasNotConfirmedCannotReleaseIt(): void
    {
        $session = $this->sessionWithRunner(PlaybackState::Playing);
        $session->control('stop', null, null, null, null, null);
        $runner = $session->activeIncarnationId();
        $revision = $session->revision();

        try {
            $session->releaseConfirmedStoppedRunner();
            $this->fail('A runner that still reports playing must keep its place.');
        } catch (DomainException $error) {
            $this->assertSame('Only confirmed stopped execution can release its runner.', $error->getMessage());
        }

        $this->assertSame($runner, $session->activeIncarnationId());
        $this->assertSame($revision, $session->revision());
    }

    public function testAPausedRunnerIsNotReleasedAsStoppedAndAStoppedRunnerIsNotReleasedAsPaused(): void
    {
        $paused = $this->sessionWithRunner(PlaybackState::Paused);
        $stopped = $this->sessionWithRunner(PlaybackState::Stopped);

        foreach ([$paused->releaseConfirmedStoppedRunner(...), $stopped->releaseConfirmedPausedRunner(...)] as $release) {
            try {
                $release();
                $this->fail('A release must match the state the runner confirmed.');
            } catch (DomainException $error) {
                $this->assertStringContainsString('can release its runner', $error->getMessage());
            }
        }

        $this->assertNotNull($paused->activeIncarnationId());
        $this->assertNotNull($stopped->activeIncarnationId());
    }

    private function sessionWithRunner(PlaybackState $state): ListeningSession
    {
        $session = ListeningSession::create(userId: $this->userId, queue: [new PublicId()->toString()], currentTrackIndex: 0, position: 45.5);
        $session->commitDestination(new Uuid(), new Uuid(), $state, 45.5);
        return $session;
    }
}
