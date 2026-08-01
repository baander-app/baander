<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\JoinPartySessionCommand;
use App\Party\Application\CommandHandler\JoinPartySessionHandler;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberJoined;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\PartyMemberState;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\Model\SyncedPartySessionState;
use App\Party\Domain\ValueObject\MemberRole;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class JoinPartySessionHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private PartyMemberPortInterface&MockObject $memberPort;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private JoinPartySessionHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(fn (object $e) => $e);
        $this->handler = new JoinPartySessionHandler($this->sessionPort, $this->memberPort, $this->eventDispatcher);
    }

    public function testReconnectsExistingMemberAndReturnsIt(): void
    {
        $userId = Uuid::v4();
        $session = $this->createActiveSession(5);

        $existingMember = PartyMember::reconstitute(new PartyMemberState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            userId: $userId,
            sessionId: $session->getId(),
            joinedAt: new DateTimeImmutable('-1 hour'),
            isConnected: false,
        ));

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->memberPort->method('countBySession')->willReturn(1);
        $this->memberPort->method('findByUserAndSession')->willReturn($existingMember);

        $this->memberPort->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PartyMember $saved): bool {
                $this->assertTrue($saved->isConnected(), 'Existing member should be reconnected.');

                return true;
            }));

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $result = ($this->handler)(new JoinPartySessionCommand($userId, $session->getId()));

        $this->assertSame($existingMember, $result);
    }

    public function testNewMemberJoinsAndDispatchesMemberJoinedEvent(): void
    {
        $userId = Uuid::v4();
        $session = $this->createActiveSession(5);
        $newMember = PartyMember::create($userId, $session->getId());

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->memberPort->method('countBySession')->willReturn(1);
        $this->memberPort->method('findByUserAndSession')->willReturn(null);
        $this->memberPort->expects($this->once())
            ->method('addMember')
            ->with($userId, $session->getId())
            ->willReturn($newMember);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (object $e) use ($userId, $session): bool {
                $this->assertInstanceOf(MemberJoined::class, $e);
                $this->assertTrue($e->getUserId()->equals($userId));
                $this->assertTrue($e->getSessionId()->equals($session->getId()));
                $this->assertSame(MemberRole::Member->value, $e->getRole());

                return true;
            }));

        $result = ($this->handler)(new JoinPartySessionCommand($userId, $session->getId()));

        $this->assertSame($newMember, $result);
    }

    public function testThrowsWhenSessionNotFound(): void
    {
        $this->sessionPort->method('findByUuid')->willReturn(null);
        $this->memberPort->expects($this->never())->method('addMember');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Party session not found or inactive.');

        ($this->handler)(new JoinPartySessionCommand(Uuid::v4(), Uuid::v4()));
    }

    public function testThrowsWhenSessionInactive(): void
    {
        $inactiveSession = SyncedPartySession::reconstitute(new SyncedPartySessionState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            hostUserId: Uuid::v4(),
            videoId: Uuid::v4(),
            transcodeJobId: Uuid::v4(),
            maxMembers: 10,
            playbackState: PlaybackState::Stopped,
            wallClockPosition: 0.0,
            playbackStartedAt: null,
            pausedAtPosition: null,
            isActive: false,
            createdAt: new DateTimeImmutable('-1 hour'),
        ));

        $this->sessionPort->method('findByUuid')->willReturn($inactiveSession);
        $this->memberPort->expects($this->never())->method('addMember');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Party session not found or inactive.');

        ($this->handler)(new JoinPartySessionCommand(Uuid::v4(), $inactiveSession->getId()));
    }

    public function testThrowsWhenSessionIsFull(): void
    {
        $session = $this->createActiveSession(2);

        $this->sessionPort->method('findByUuid')->willReturn($session);
        $this->memberPort->method('countBySession')->willReturn(2);
        $this->memberPort->expects($this->never())->method('addMember');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Party session is full.');

        ($this->handler)(new JoinPartySessionCommand(Uuid::v4(), $session->getId()));
    }

    private function createActiveSession(int $maxMembers = 10): SyncedPartySession
    {
        return SyncedPartySession::create(Uuid::v4(), Uuid::v4(), Uuid::v4(), $maxMembers);
    }
}
