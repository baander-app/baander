<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\LeavePartySessionCommand;
use App\Party\Application\CommandHandler\LeavePartySessionHandler;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\PartySessionEnded;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\PartyMemberState;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class LeavePartySessionHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private PartyMemberPortInterface&MockObject $memberPort;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private LeavePartySessionHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(fn (object $e) => $e);
        $this->handler = new LeavePartySessionHandler($this->sessionPort, $this->memberPort, $this->eventDispatcher);
    }

    public function testNonHostMemberLeavesAndIsRemovedWithoutPromotion(): void
    {
        $hostUserId = Uuid::v4();
        $memberUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), Uuid::v4());

        $this->sessionPort->method('findByUuid')->willReturn($session);

        $this->memberPort->expects($this->once())
            ->method('removeMember')
            ->with($memberUserId, $session->getId());

        $this->memberPort->expects($this->never())->method('findBySession');
        $this->memberPort->expects($this->never())->method('save');
        $this->sessionPort->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handler)(new LeavePartySessionCommand($memberUserId, $session->getId()));
    }

    public function testHostLeavingWithRemainingMembersPromotesEarliestJoined(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), Uuid::v4());
        $sessionId = $session->getId();

        // Create remaining members with deliberately out-of-order joinedAt timestamps
        $earliestMember = $this->buildMember($sessionId, new DateTimeImmutable('2026-01-01T10:00:00'));
        $middleMember = $this->buildMember($sessionId, new DateTimeImmutable('2026-01-01T11:00:00'));
        $latestMember = $this->buildMember($sessionId, new DateTimeImmutable('2026-01-01T12:00:00'));

        $this->sessionPort->method('findByUuid')->willReturn($session);

        $this->memberPort->expects($this->once())
            ->method('removeMember')
            ->with($hostUserId, $sessionId);

        // Return members in non-sorted order to verify usort
        $this->memberPort->expects($this->once())
            ->method('findBySession')
            ->with($sessionId)
            ->willReturn([$latestMember, $earliestMember, $middleMember]);

        // Only the earliest-joined member should be saved (promoted to host)
        $this->memberPort->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PartyMember $member) use ($earliestMember): bool {
                $this->assertTrue($member->getUserId()->equals($earliestMember->getUserId()));
                $this->assertSame(MemberRole::Host, $member->getRole(), 'Earliest member should be promoted to host.');

                return true;
            }));

        $this->sessionPort->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handler)(new LeavePartySessionCommand($hostUserId, $sessionId));
    }

    public function testHostLeavingWithNoRemainingMembersEndsSessionAndDispatchesEvent(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), Uuid::v4());
        $sessionId = $session->getId();

        $this->sessionPort->method('findByUuid')->willReturn($session);

        $this->memberPort->expects($this->once())
            ->method('removeMember')
            ->with($hostUserId, $sessionId);

        $this->memberPort->expects($this->once())
            ->method('findBySession')
            ->with($sessionId)
            ->willReturn([]);

        $this->sessionPort->expects($this->once())
            ->method('save')
            ->with($this->callback(function (SyncedPartySession $saved) use ($hostUserId): bool {
                $this->assertFalse($saved->isActive(), 'Session should be ended.');
                $this->assertSame(PlaybackState::Stopped, $saved->getPlaybackState());
                $this->assertTrue($saved->getHostUserId()->equals($hostUserId));

                return true;
            }));

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (PartySessionEnded $event) use ($sessionId, $hostUserId): bool {
                $this->assertTrue($event->getSessionId()->equals($sessionId));
                $this->assertTrue($event->getHostUserId()->equals($hostUserId));

                return true;
            }));

        ($this->handler)(new LeavePartySessionCommand($hostUserId, $sessionId));
    }

    public function testNoOpWhenSessionNotFound(): void
    {
        $sessionId = Uuid::v4();

        $this->sessionPort->expects($this->once())
            ->method('findByUuid')
            ->with($sessionId)
            ->willReturn(null);

        $this->memberPort->expects($this->never())->method('removeMember');
        $this->memberPort->expects($this->never())->method('findBySession');
        $this->sessionPort->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        ($this->handler)(new LeavePartySessionCommand(Uuid::v4(), $sessionId));
    }

    private function buildMember(Uuid $sessionId, DateTimeImmutable $joinedAt): PartyMember
    {
        return PartyMember::reconstitute(new PartyMemberState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            userId: Uuid::v4(),
            sessionId: $sessionId,
            joinedAt: $joinedAt,
        ));
    }
}
