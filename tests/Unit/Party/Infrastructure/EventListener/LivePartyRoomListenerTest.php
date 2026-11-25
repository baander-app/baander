<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Infrastructure\EventListener;

use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberLeft;
use App\Party\Domain\Event\PartySessionEnded;
use App\Party\Domain\Event\PlaybackStateChanged;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Party\Infrastructure\EventListener\LivePartyRoomListener;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\LivePartyRoomsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class LivePartyRoomListenerTest extends TestCase
{
    public function testAMemberWhoLeftIsTakenOutOfTheRoom(): void
    {
        $userId = Uuid::generate();
        $session = SyncedPartySession::create($userId, new Uuid(), null);
        $sessionId = $session->getId();
        $userPublicId = new PublicId();
        $rooms = $this->createMock(LivePartyRoomsPortInterface::class);
        $rooms->expects(self::once())->method('removeMember')->with($sessionId, $userId, $session->getPublicId(), $userPublicId);
        $rooms->expects(self::never())->method('close');

        $this->dispatcher($rooms, $session, $userPublicId)->dispatch(new MemberLeft($sessionId, $userId));
    }

    public function testAnEndedPartyEmptiesItsRoom(): void
    {
        $sessionId = Uuid::generate();
        $rooms = $this->createMock(LivePartyRoomsPortInterface::class);
        $rooms->expects(self::once())->method('close')->with($sessionId);
        $rooms->expects(self::never())->method('removeMember');

        $this->dispatcher($rooms)->dispatch(new PartySessionEnded($sessionId, Uuid::generate()));
    }

    public function testTheHostsPlaybackStateReachesTheRoom(): void
    {
        $sessionId = Uuid::generate();
        $sessionPublicId = new PublicId();
        $updatedAt = new \DateTimeImmutable('2026-11-17T09:00:00+00:00');
        $rooms = $this->createMock(LivePartyRoomsPortInterface::class);
        $rooms->expects(self::once())->method('broadcastPlayback')->with(
            $sessionId,
            $sessionPublicId,
            'paused',
            42.5,
            40.0,
            $updatedAt,
        );
        $rooms->expects(self::never())->method('removeMember');
        $rooms->expects(self::never())->method('close');

        $this->dispatcher($rooms)->dispatch(new PlaybackStateChanged(
            sessionId: $sessionId,
            sessionPublicId: $sessionPublicId,
            playbackState: PlaybackState::Paused,
            currentPosition: 42.5,
            wallClockPosition: 40.0,
            updatedAt: $updatedAt,
        ));
    }

    public function testMissingSessionClosesRoomBeforePropagatingFailure(): void
    {
        $sessionId = Uuid::generate();
        $closed = false;
        $rooms = $this->createMock(LivePartyRoomsPortInterface::class);
        $rooms->expects(self::once())->method('close')->with($sessionId)
            ->willReturnCallback(static function () use (&$closed): void { $closed = true; });
        $rooms->expects(self::never())->method('removeMember');

        try {
            $this->dispatcher($rooms)->dispatch(new MemberLeft($sessionId, Uuid::generate()));
            self::fail('Missing session must propagate its failure.');
        } catch (NotFoundException $error) {
            self::assertTrue($closed, 'Room must close before the lookup failure propagates.');
            self::assertSame('Party session not found.', $error->getMessage());
        }
    }

    public function testUserProjectionOutageClosesRoomBeforePropagatingOriginalFailure(): void
    {
        $userId = Uuid::generate();
        $session = SyncedPartySession::create($userId, new Uuid(), null);
        $closed = false;
        $rooms = $this->createMock(LivePartyRoomsPortInterface::class);
        $rooms->expects(self::once())->method('close')->with($session->getId())
            ->willReturnCallback(static function () use (&$closed): void { $closed = true; });
        $rooms->expects(self::never())->method('removeMember');
        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($session);
        $failure = new \RuntimeException('Identity read unavailable');
        $identities = $this->createStub(PartyPublicIdentityPortInterface::class);
        $identities->method('userPublicId')->willThrowException($failure);
        $listener = new LivePartyRoomListener($rooms, $sessions, $identities);

        try {
            $listener->onMemberLeft(new MemberLeft($session->getId(), $userId));
            self::fail('Identity outage must propagate its failure.');
        } catch (\RuntimeException $error) {
            self::assertTrue($closed, 'Room must close before the lookup failure propagates.');
            self::assertSame($failure, $error);
        }
    }

    private function dispatcher(LivePartyRoomsPortInterface $rooms, ?SyncedPartySession $session = null, ?PublicId $userPublicId = null): EventDispatcher
    {
        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($session);
        $identities = $this->createStub(PartyPublicIdentityPortInterface::class);
        $identities->method('userPublicId')->willReturn($userPublicId ?? new PublicId());
        $listener = new LivePartyRoomListener($rooms, $sessions, $identities);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MemberLeft::class, $listener->onMemberLeft(...));
        $dispatcher->addListener(PartySessionEnded::class, $listener->onSessionEnded(...));
        $dispatcher->addListener(PlaybackStateChanged::class, $listener->onPlaybackStateChanged(...));

        return $dispatcher;
    }
}
