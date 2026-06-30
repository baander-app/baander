<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Domain\Model;

use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\Model\SyncedPartySessionState;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SyncedPartySessionTest extends TestCase
{
    public function testCreateGeneratesIdentityAndInitialStoppedState(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $this->assertInstanceOf(Uuid::class, $session->getId());
        $this->assertInstanceOf(PublicId::class, $session->getPublicId());
        $this->assertSame(PlaybackState::Stopped, $session->getPlaybackState());
        $this->assertSame(0.0, $session->getWallClockPosition());
        $this->assertNull($session->getPlaybackStartedAt());
        $this->assertNull($session->getPausedAtPosition());
        $this->assertTrue($session->isActive());
        $this->assertSame(10, $session->getMaxMembers());
    }

    public function testCreateWithCustomMaxMembers(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
            maxMembers: 25,
        );

        $this->assertSame(25, $session->getMaxMembers());
    }

    public function testCreateThrowsOnMaxMembersBelowTwo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Max members must be at least 2.');

        SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
            maxMembers: 1,
        );
    }

    public function testStartPlaybackSetsStateToPlaying(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(10.0);

        $this->assertSame(PlaybackState::Playing, $session->getPlaybackState());
        $this->assertSame(10.0, $session->getWallClockPosition());
        $this->assertNotNull($session->getPlaybackStartedAt());
        $this->assertNull($session->getPausedAtPosition());
    }

    public function testStartPlaybackFromZeroWhenNoPositionGiven(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback();

        $this->assertSame(PlaybackState::Playing, $session->getPlaybackState());
        $this->assertSame(0.0, $session->getWallClockPosition());
    }

    public function testPausePlaybackCapturesPositionAndSetsPaused(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(30.0);
        $session->pausePlayback();

        $this->assertSame(PlaybackState::Paused, $session->getPlaybackState());
        $this->assertNull($session->getPlaybackStartedAt());
        $this->assertNotNull($session->getPausedAtPosition());
        $this->assertGreaterThanOrEqual(30.0, $session->getPausedAtPosition());
    }

    public function testPausePlaybackWhenAlreadyPausedIsIdempotent(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(10.0);
        $session->pausePlayback();
        $pausedAt = $session->getUpdatedAt();

        // Pausing again should be a no-op
        $session->pausePlayback();

        $this->assertSame(PlaybackState::Paused, $session->getPlaybackState());
        $this->assertSame($pausedAt, $session->getUpdatedAt(), 'updatedAt should not change on idempotent pause.');
    }

    public function testPausePlaybackThrowsWhenNotPlaying(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot pause playback: session is not currently playing.');

        $session->pausePlayback();
    }

    public function testResumePlaybackFromPausedPosition(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(20.0);
        $session->pausePlayback();
        $pausedPosition = $session->getPausedAtPosition();

        $session->startPlayback();

        $this->assertSame(PlaybackState::Playing, $session->getPlaybackState());
        $this->assertSame($pausedPosition, $session->getWallClockPosition());
    }

    public function testSeekToWhilePlayingResetsTimestamp(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(0.0);
        $session->seekTo(100.0);

        $this->assertSame(100.0, $session->getWallClockPosition());
        $this->assertSame(PlaybackState::Playing, $session->getPlaybackState());
        $this->assertNotNull($session->getPlaybackStartedAt());
    }

    public function testSeekToWhilePausedUpdatesPausedPosition(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(0.0);
        $session->pausePlayback();
        $session->seekTo(50.0);

        $this->assertSame(50.0, $session->getPausedAtPosition());
        $this->assertSame(PlaybackState::Paused, $session->getPlaybackState());
    }

    public function testSeekToThrowsOnNegativePosition(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seek position cannot be negative.');

        $session->seekTo(-1.0);
    }

    public function testSyncPlaybackReturnsServerPositionWhenWithinTolerance(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(0.0);

        $serverPos = $session->getCurrentPosition();
        $result = $session->syncPlayback($serverPos, 0.5);

        // Should return the server position since client is in sync
        $this->assertSame($serverPos, $result);
    }

    public function testSyncPlaybackReturnsServerPositionWhenDriftExceedsTolerance(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(0.0);

        $serverPos = $session->getCurrentPosition();
        // Client is way behind
        $result = $session->syncPlayback(0.0, 0.5);

        // Should still return server position for correction
        $this->assertSame($serverPos, $result);
    }

    public function testSyncPlaybackReturnsWallClockPositionWhenNotPlaying(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(50.0);
        $session->pausePlayback();

        $result = $session->syncPlayback(10.0, 0.5);

        $this->assertSame(50.0, $result);
    }

    public function testEndSessionStopsPlaybackAndDeactivates(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(0.0);
        $session->endSession();

        $this->assertSame(PlaybackState::Stopped, $session->getPlaybackState());
        $this->assertFalse($session->isActive());
        $this->assertNull($session->getPlaybackStartedAt());
        $this->assertNull($session->getPausedAtPosition());
    }

    public function testTransferHostChangesHostUserId(): void
    {
        $originalHost = Uuid::v4();
        $newHost = Uuid::v4();

        $session = SyncedPartySession::create(
            $originalHost,
            Uuid::v4(),
            Uuid::v4(),
        );

        $this->assertTrue($session->getHostUserId()->equals($originalHost));

        $session->transferHost($newHost);

        $this->assertTrue($session->getHostUserId()->equals($newHost));
    }

    public function testGetCurrentPositionReturnsWallClockWhenStopped(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $this->assertSame(0.0, $session->getCurrentPosition());
    }

    public function testGetCurrentPositionReturnsPausedPositionWhenPaused(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $session->startPlayback(25.0);
        $session->pausePlayback();

        $this->assertSame($session->getPausedAtPosition(), $session->getCurrentPosition());
    }

    public function testReconstituteRestoresAllFields(): void
    {
        $now = new \DateTimeImmutable();
        $hostUserId = Uuid::v4();
        $videoId = Uuid::v4();
        $transcodeJobId = Uuid::v4();

        $state = new SyncedPartySessionState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            hostUserId: $hostUserId,
            videoId: $videoId,
            transcodeJobId: $transcodeJobId,
            maxMembers: 5,
            playbackState: PlaybackState::Paused,
            wallClockPosition: 42.5,
            playbackStartedAt: null,
            pausedAtPosition: 42.5,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );

        $session = SyncedPartySession::reconstitute($state);

        $this->assertTrue($session->getHostUserId()->equals($hostUserId));
        $this->assertTrue($session->getVideoId()->equals($videoId));
        $this->assertSame(5, $session->getMaxMembers());
        $this->assertSame(PlaybackState::Paused, $session->getPlaybackState());
        $this->assertSame(42.5, $session->getWallClockPosition());
        $this->assertSame(42.5, $session->getPausedAtPosition());
        $this->assertTrue($session->isActive());
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $this->assertInstanceOf(Uuid::class, $session->getId());
        $this->assertInstanceOf(PublicId::class, $session->getPublicId());
        $this->assertInstanceOf(Uuid::class, $session->getHostUserId());
        $this->assertInstanceOf(Uuid::class, $session->getVideoId());
        $this->assertInstanceOf(Uuid::class, $session->getTranscodeJobId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $session->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $session->getUpdatedAt());
    }

    public function testCreatedAtAndUpdatedAtAreCloseOnCreation(): void
    {
        $session = SyncedPartySession::create(
            Uuid::v4(),
            Uuid::v4(),
            Uuid::v4(),
        );

        $diff = $session->getUpdatedAt()->getTimestamp() - $session->getCreatedAt()->getTimestamp();
        $this->assertSame(0, $diff, 'createdAt and updatedAt should have the same second on creation.');
    }
}
