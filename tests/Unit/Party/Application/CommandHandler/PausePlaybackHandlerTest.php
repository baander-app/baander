<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\PausePlaybackCommand;
use App\Party\Application\CommandHandler\PausePlaybackHandler;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\PlaybackStateChanged;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class PausePlaybackHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private PausePlaybackHandler $handler;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->dispatched = [];
        $eventDispatcher->method('dispatch')->willReturnCallback(function (object $e): object {
            $this->dispatched[] = $e;

            return $e;
        });
        $this->handler = new PausePlaybackHandler($this->sessionPort, $eventDispatcher);
    }

    public function testHostPauseSteersTheEncoderThenBroadcastsThePausedState(): void
    {
        $hostUserId = Uuid::v4();
        $transcodeJobId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), $transcodeJobId);
        $session->startPlayback(30.0);
        $this->portPausesPlaybackOn($session);

        ($this->handler)(new PausePlaybackCommand($session->getId(), $hostUserId));

        self::assertCount(2, $this->dispatched);
        $encoder = $this->dispatched[0];
        self::assertInstanceOf(PlaybackPositionChanged::class, $encoder);
        self::assertTrue($encoder->getJobId()->equals($transcodeJobId));
        self::assertSame('pause', $encoder->getAction());
        self::assertEqualsWithDelta(30.0, $encoder->getPosition(), 1.0);
        $broadcast = $this->dispatched[1];
        self::assertInstanceOf(PlaybackStateChanged::class, $broadcast);
        self::assertTrue($broadcast->getSessionId()->equals($session->getId()));
        self::assertSame($session->getPublicId()->toString(), $broadcast->getSessionPublicId()->toString());
        self::assertSame(PlaybackState::Paused, $broadcast->getPlaybackState());
        self::assertSame($session->getPausedAtPosition(), $broadcast->getCurrentPosition());
        self::assertSame(30.0, $broadcast->getWallClockPosition());
        self::assertSame($session->getUpdatedAt(), $broadcast->getUpdatedAt());
    }

    public function testNoOpWhenSessionNotFound(): void
    {
        $this->sessionPort->method('findByUuid')->willReturn(null);
        $this->sessionPort->expects($this->never())->method('pausePlayback');

        ($this->handler)(new PausePlaybackCommand(Uuid::v4(), Uuid::v4()));

        self::assertSame([], $this->dispatched);
    }

    public function testAnotherUsersPauseChangesAndBroadcastsNothing(): void
    {
        $session = SyncedPartySession::create(Uuid::v4(), Uuid::v4(), Uuid::v4());
        $session->startPlayback(30.0);

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->never())->method('pausePlayback');

        ($this->handler)(new PausePlaybackCommand($session->getId(), Uuid::v4()));

        self::assertSame([], $this->dispatched);
    }

    public function testPauseWithoutTranscodeJobBroadcastsTheStateButSteersNoEncoder(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), null);
        $session->startPlayback(12.0);
        $this->portPausesPlaybackOn($session);

        ($this->handler)(new PausePlaybackCommand($session->getId(), $hostUserId));

        self::assertCount(1, $this->dispatched);
        $broadcast = $this->dispatched[0];
        self::assertInstanceOf(PlaybackStateChanged::class, $broadcast);
        self::assertSame(PlaybackState::Paused, $broadcast->getPlaybackState());
        self::assertSame($session->getPausedAtPosition(), $broadcast->getCurrentPosition());
    }

    /** The port changes the same session the handler reads back afterwards. */
    private function portPausesPlaybackOn(SyncedPartySession $session): void
    {
        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->once())
            ->method('pausePlayback')
            ->with($session->getId())
            ->willReturnCallback(static fn () => $session->pausePlayback());
    }
}
