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
        $sessionId = Uuid::generate();
        $userId = Uuid::generate();
        $rooms = $this->createMock(LivePartyRoomsPortInterface::class);
        $rooms->expects(self::once())->method('removeMember')->with($sessionId, $userId);
        $rooms->expects(self::never())->method('close');

        $this->dispatcher($rooms)->dispatch(new MemberLeft($sessionId, $userId));
    }

    public function testAnEndedPartyEmptiesItsRoom(): void
    {
        $sessionId = Uuid::generate();
        $rooms = $this->createMock(LivePartyRoomsPortInterface::class);
        $rooms->expects(self::once())->method('close')->with($sessionId);
        $rooms->expects(self::never())->method('removeMember');

        $this->dispatcher($rooms)->dispatch(new PartySessionEnded($sessionId, Uuid::generate()));
    }

    private function dispatcher(LivePartyRoomsPortInterface $rooms): EventDispatcher
    {
        $listener = new LivePartyRoomListener($rooms);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MemberLeft::class, $listener->onMemberLeft(...));
        $dispatcher->addListener(PartySessionEnded::class, $listener->onSessionEnded(...));

        return $dispatcher;
    }
}
