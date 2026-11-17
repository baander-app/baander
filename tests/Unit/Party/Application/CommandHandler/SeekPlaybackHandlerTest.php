<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\SeekPlaybackCommand;
use App\Party\Application\CommandHandler\SeekPlaybackHandler;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\PlaybackStateChanged;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SeekPlaybackHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private SeekPlaybackHandler $handler;

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
        $this->handler = new SeekPlaybackHandler($this->sessionPort, $eventDispatcher);
    }

    public function testHostSeekSteersTheEncoderThenBroadcastsTheNewPosition(): void
    {
        $hostUserId = Uuid::v4();
        $transcodeJobId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), $transcodeJobId);
        $this->portSeeksOn($session, 120.0);

        ($this->handler)(new SeekPlaybackCommand($session->getId(), $hostUserId, 120.0));

        self::assertCount(2, $this->dispatched);
        $encoder = $this->dispatched[0];
        self::assertInstanceOf(PlaybackPositionChanged::class, $encoder);
        self::assertTrue($encoder->getJobId()->equals($transcodeJobId));
        self::assertSame(120.0, $encoder->getPosition());
        self::assertSame('seek', $encoder->getAction());
        $this->assertBroadcast($this->dispatched[1], $session, 120.0);
    }

    public function testNoOpWhenSessionNotFound(): void
    {
        $this->sessionPort->method('findByUuid')->willReturn(null);
        $this->sessionPort->expects($this->never())->method('seekTo');

        ($this->handler)(new SeekPlaybackCommand(Uuid::v4(), Uuid::v4(), 50.0));

        self::assertSame([], $this->dispatched);
    }

    public function testAnotherUsersSeekChangesAndBroadcastsNothing(): void
    {
        $session = SyncedPartySession::create(Uuid::v4(), Uuid::v4(), Uuid::v4());

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->never())->method('seekTo');

        ($this->handler)(new SeekPlaybackCommand($session->getId(), Uuid::v4(), 50.0));

        self::assertSame([], $this->dispatched);
    }

    public function testDefaultPositionIsZero(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), Uuid::v4());
        $this->portSeeksOn($session, 0.0);

        ($this->handler)(new SeekPlaybackCommand($session->getId(), $hostUserId));

        self::assertCount(2, $this->dispatched);
        $encoder = $this->dispatched[0];
        self::assertInstanceOf(PlaybackPositionChanged::class, $encoder);
        self::assertSame(0.0, $encoder->getPosition());
        self::assertSame('seek', $encoder->getAction());
        $this->assertBroadcast($this->dispatched[1], $session, 0.0);
    }

    public function testSeekWithoutTranscodeJobBroadcastsTheStateButSteersNoEncoder(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), null);
        $this->portSeeksOn($session, 45.0);

        ($this->handler)(new SeekPlaybackCommand($session->getId(), $hostUserId, 45.0));

        self::assertCount(1, $this->dispatched);
        $this->assertBroadcast($this->dispatched[0], $session, 45.0);
    }

    /** The port changes the same session the handler reads back afterwards. */
    private function portSeeksOn(SyncedPartySession $session, float $position): void
    {
        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->once())
            ->method('seekTo')
            ->with($session->getId(), $position)
            ->willReturnCallback(static fn (Uuid $id, float $to) => $session->seekTo($to));
    }

    /** A stopped session that seeks stays stopped, parked at the new position. */
    private function assertBroadcast(object $event, SyncedPartySession $session, float $position): void
    {
        self::assertInstanceOf(PlaybackStateChanged::class, $event);
        self::assertTrue($event->getSessionId()->equals($session->getId()));
        self::assertSame($session->getPublicId()->toString(), $event->getSessionPublicId()->toString());
        self::assertSame(PlaybackState::Stopped, $event->getPlaybackState());
        self::assertSame($position, $event->getCurrentPosition());
        self::assertSame($position, $event->getWallClockPosition());
        self::assertSame($session->getUpdatedAt(), $event->getUpdatedAt());
    }
}
