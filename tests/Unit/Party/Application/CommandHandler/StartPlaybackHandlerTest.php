<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\StartPlaybackCommand;
use App\Party\Application\CommandHandler\StartPlaybackHandler;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\PlaybackStateChanged;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Event\PlaybackPositionChanged;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class StartPlaybackHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private StartPlaybackHandler $handler;

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
        $this->handler = new StartPlaybackHandler($this->sessionPort, $eventDispatcher);
    }

    public function testHostStartSteersTheEncoderAtThatPositionThenBroadcastsThePlayingState(): void
    {
        $hostUserId = Uuid::v4();
        $transcodeJobId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), $transcodeJobId);
        $this->portStartsPlaybackOn($session, 100.0);

        ($this->handler)(new StartPlaybackCommand($session->getId(), $hostUserId, 100.0));

        self::assertCount(2, $this->dispatched);
        $encoder = $this->dispatched[0];
        self::assertInstanceOf(PlaybackPositionChanged::class, $encoder);
        self::assertTrue($encoder->getJobId()->equals($transcodeJobId));
        self::assertSame(100.0, $encoder->getPosition());
        self::assertSame('resume', $encoder->getAction());
        $this->assertBroadcast($this->dispatched[1], $session, PlaybackState::Playing, 100.0);
    }

    public function testHostStartWithoutPositionSteersTheEncoderAtTheCurrentPosition(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), Uuid::v4());
        $this->portStartsPlaybackOn($session, null);

        ($this->handler)(new StartPlaybackCommand($session->getId(), $hostUserId));

        self::assertCount(2, $this->dispatched);
        $encoder = $this->dispatched[0];
        self::assertInstanceOf(PlaybackPositionChanged::class, $encoder);
        // A stopped session starts from its wall clock position, 0.0, and has barely advanced since.
        self::assertEqualsWithDelta(0.0, $encoder->getPosition(), 1.0);
        self::assertSame('resume', $encoder->getAction());
        $this->assertBroadcast($this->dispatched[1], $session, PlaybackState::Playing, 0.0);
    }

    public function testNoOpWhenSessionNotFound(): void
    {
        $this->sessionPort->method('findByUuid')->willReturn(null);
        $this->sessionPort->expects($this->never())->method('startPlayback');

        ($this->handler)(new StartPlaybackCommand(Uuid::v4(), Uuid::v4(), 50.0));

        self::assertSame([], $this->dispatched);
    }

    public function testAnotherUsersStartChangesAndBroadcastsNothing(): void
    {
        $session = SyncedPartySession::create(Uuid::v4(), Uuid::v4(), Uuid::v4());

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->never())->method('startPlayback');

        ($this->handler)(new StartPlaybackCommand($session->getId(), Uuid::v4(), 50.0));

        self::assertSame([], $this->dispatched);
    }

    public function testStartWithoutTranscodeJobBroadcastsTheStateButSteersNoEncoder(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), null);
        $this->portStartsPlaybackOn($session, 30.0);

        ($this->handler)(new StartPlaybackCommand($session->getId(), $hostUserId, 30.0));

        self::assertCount(1, $this->dispatched);
        $this->assertBroadcast($this->dispatched[0], $session, PlaybackState::Playing, 30.0);
    }

    public function testOnceTheHostRoleMovesOnlyTheNewHostsStartAppliesAndSteersTheFormerHostsJob(): void
    {
        $formerHostUserId = Uuid::v4();
        $newHostUserId = Uuid::v4();
        $transcodeJobId = Uuid::v4();
        $session = SyncedPartySession::create($formerHostUserId, Uuid::v4(), $transcodeJobId);
        $session->transferHost($newHostUserId);
        $this->portStartsPlaybackOn($session, 15.0);

        ($this->handler)(new StartPlaybackCommand($session->getId(), $formerHostUserId, 99.0));
        ($this->handler)(new StartPlaybackCommand($session->getId(), $newHostUserId, 15.0));

        // The port saw one start, at the new host's position, and only that start steered and broadcast.
        self::assertCount(2, $this->dispatched);
        $encoder = $this->dispatched[0];
        self::assertInstanceOf(PlaybackPositionChanged::class, $encoder);
        self::assertTrue($encoder->getJobId()->equals($transcodeJobId));
        self::assertSame(15.0, $encoder->getPosition());
        $this->assertBroadcast($this->dispatched[1], $session, PlaybackState::Playing, 15.0);
    }

    /** The port changes the same session the handler reads back afterwards. */
    private function portStartsPlaybackOn(SyncedPartySession $session, ?float $position): void
    {
        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->sessionPort->expects($this->once())
            ->method('startPlayback')
            ->with($session->getId(), $position)
            ->willReturnCallback(static fn (Uuid $id, ?float $at = null) => $session->startPlayback($at));
    }

    private function assertBroadcast(object $event, SyncedPartySession $session, PlaybackState $state, float $wallClockPosition): void
    {
        self::assertInstanceOf(PlaybackStateChanged::class, $event);
        self::assertTrue($event->getSessionId()->equals($session->getId()));
        self::assertSame($session->getPublicId()->toString(), $event->getSessionPublicId()->toString());
        self::assertSame($state, $event->getPlaybackState());
        self::assertSame($wallClockPosition, $event->getWallClockPosition());
        self::assertEqualsWithDelta($wallClockPosition, $event->getCurrentPosition(), 1.0);
        self::assertSame($session->getUpdatedAt(), $event->getUpdatedAt());
    }
}
