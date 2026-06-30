<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\TransferHostCommand;
use App\Party\Application\CommandHandler\TransferHostHandler;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TransferHostHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private PartyMemberPortInterface&MockObject $memberPort;
    private TransferHostHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->handler = new TransferHostHandler($this->sessionPort, $this->memberPort);
    }

    public function testTransfersHostPromotesNewAndDemotesOld(): void
    {
        $sessionId = Uuid::v4();
        $currentHostUserId = Uuid::v4();
        $newHostUserId = Uuid::v4();

        $oldHostMember = PartyMember::create($currentHostUserId, $sessionId, MemberRole::Host);
        $newHostMember = PartyMember::create($newHostUserId, $sessionId, MemberRole::Member);

        $this->sessionPort->expects($this->once())
            ->method('transferHost')
            ->with($sessionId, $newHostUserId);

        $this->memberPort->expects($this->exactly(2))
            ->method('findByUserAndSession')
            ->willReturnOnConsecutiveCalls($newHostMember, $oldHostMember);

        // New host member should be promoted before save
        $this->memberPort->expects($this->exactly(2))
            ->method('save')
            ->with($this->callback(function (PartyMember $member) use ($newHostUserId, $currentHostUserId): bool {
                if ($member->getUserId()->equals($newHostUserId)) {
                    $this->assertSame(MemberRole::Host, $member->getRole(), 'New host should be promoted to Host.');
                }
                if ($member->getUserId()->equals($currentHostUserId)) {
                    $this->assertSame(MemberRole::Member, $member->getRole(), 'Old host should be demoted to Member.');
                }

                return true;
            }));

        ($this->handler)(new TransferHostCommand($sessionId, $currentHostUserId, $newHostUserId));
    }

    public function testDemotesOldHostWhenNewHostMemberNotFound(): void
    {
        $sessionId = Uuid::v4();
        $currentHostUserId = Uuid::v4();
        $newHostUserId = Uuid::v4();

        $oldHostMember = PartyMember::create($currentHostUserId, $sessionId, MemberRole::Host);

        $this->sessionPort->expects($this->once())->method('transferHost');

        $this->memberPort->expects($this->exactly(2))
            ->method('findByUserAndSession')
            ->willReturnOnConsecutiveCalls(null, $oldHostMember);

        // Only old host is saved (new host member not found)
        $this->memberPort->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PartyMember $member): bool {
                $this->assertSame(MemberRole::Member, $member->getRole());

                return true;
            }));

        ($this->handler)(new TransferHostCommand($sessionId, $currentHostUserId, $newHostUserId));
    }

    public function testPromotesNewHostWhenOldHostMemberNotFound(): void
    {
        $sessionId = Uuid::v4();
        $currentHostUserId = Uuid::v4();
        $newHostUserId = Uuid::v4();

        $newHostMember = PartyMember::create($newHostUserId, $sessionId, MemberRole::Member);

        $this->sessionPort->expects($this->once())->method('transferHost');

        $this->memberPort->expects($this->exactly(2))
            ->method('findByUserAndSession')
            ->willReturnOnConsecutiveCalls($newHostMember, null);

        // Only new host is saved (old host member not found)
        $this->memberPort->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PartyMember $member): bool {
                $this->assertSame(MemberRole::Host, $member->getRole());

                return true;
            }));

        ($this->handler)(new TransferHostCommand($sessionId, $currentHostUserId, $newHostUserId));
    }

    public function testTransferHostOnPortWhenNeitherMemberFound(): void
    {
        $sessionId = Uuid::v4();
        $currentHostUserId = Uuid::v4();
        $newHostUserId = Uuid::v4();

        $this->sessionPort->expects($this->once())->method('transferHost');

        $this->memberPort->method('findByUserAndSession')->willReturn(null);
        $this->memberPort->expects($this->never())->method('save');

        ($this->handler)(new TransferHostCommand($sessionId, $currentHostUserId, $newHostUserId));
    }
}
