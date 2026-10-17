<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Infrastructure\EventListener;

use App\Party\Domain\Event\MemberLeft;
use App\Party\Domain\Event\PartySessionEnded;
use App\Party\Infrastructure\EventListener\LivePartyRoomListener;
use App\Shared\Application\Port\LivePartyRoomsPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class LivePartyRoomListenerTest extends TestCase
{
    public function testAMemberWhoLeftIsTakenOutOfTheRoom(): void
    {
        $userId = Uuid::generate();
        $session = \App\Party\Domain\Model\SyncedPartySession::create($userId, new Uuid(), null);
        $sessionId = $session->getId();
        $userPublicId = new \App\Shared\Domain\Model\PublicId();
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
        } catch (\App\Shared\Application\Exception\NotFoundException $error) {
            self::assertTrue($closed, 'Room must close before the lookup failure propagates.');
            self::assertSame('Party session not found.', $error->getMessage());
        }
    }

    public function testUserProjectionOutageClosesRoomBeforePropagatingOriginalFailure(): void
    {
        $userId = Uuid::generate();
        $session = \App\Party\Domain\Model\SyncedPartySession::create($userId, new Uuid(), null);
        $closed = false;
        $rooms = $this->createMock(LivePartyRoomsPortInterface::class);
        $rooms->expects(self::once())->method('close')->with($session->getId())
            ->willReturnCallback(static function () use (&$closed): void { $closed = true; });
        $rooms->expects(self::never())->method('removeMember');
        $sessions = $this->createStub(\App\Party\Application\Port\PartySessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($session);
        $failure = new \RuntimeException('Identity read unavailable');
        $identities = $this->createStub(\App\Party\Application\Port\PartyPublicIdentityPortInterface::class);
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

    private function dispatcher(LivePartyRoomsPortInterface $rooms, ?\App\Party\Domain\Model\SyncedPartySession $session = null, ?\App\Shared\Domain\Model\PublicId $userPublicId = null): EventDispatcher
    {
        $sessions = $this->createStub(\App\Party\Application\Port\PartySessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($session);
        $identities = $this->createStub(\App\Party\Application\Port\PartyPublicIdentityPortInterface::class);
        $identities->method('userPublicId')->willReturn($userPublicId ?? new \App\Shared\Domain\Model\PublicId());
        $listener = new LivePartyRoomListener($rooms, $sessions, $identities);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MemberLeft::class, $listener->onMemberLeft(...));
        $dispatcher->addListener(PartySessionEnded::class, $listener->onSessionEnded(...));

        return $dispatcher;
    }
}
