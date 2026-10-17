<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\TransferHostCommand;
use App\Party\Application\CommandHandler\TransferHostHandler;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TransferHostHandlerTest extends TestCase
{
    public function testTransfersHostOnlyToAnExistingMemberAndPreservesInternalCommands(): void
    {
        $host = new Uuid();
        $target = new Uuid();
        $session = SyncedPartySession::create($host, new Uuid(), null);
        $oldMember = PartyMember::create($host, $session->getId(), MemberRole::Host);
        $newMember = PartyMember::create($target, $session->getId());
        $sessions = $this->createMock(PartySessionPortInterface::class);
        $sessions->expects($this->once())->method('findByUuid')->with($session->getId())->willReturn($session);
        $sessions->expects($this->once())->method('transferHost')->with($session->getId(), $target);
        $members = $this->createMock(PartyMemberPortInterface::class);
        $members->expects($this->exactly(2))->method('findByUserAndSession')->willReturnOnConsecutiveCalls($newMember, $oldMember);
        $members->expects($this->exactly(2))->method('save');

        (new TransferHostHandler($sessions, $members))(new TransferHostCommand($session->getId(), $host, $target));

        self::assertSame(MemberRole::Host, $newMember->getRole());
        self::assertSame(MemberRole::Member, $oldMember->getRole());
    }

    public function testNonHostCannotTransferTheParty(): void
    {
        $session = SyncedPartySession::create(new Uuid(), new Uuid(), null);
        $sessions = $this->createMock(PartySessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($session);
        $sessions->expects($this->never())->method('transferHost');
        $members = $this->createMock(PartyMemberPortInterface::class);
        $members->expects($this->never())->method('findByUserAndSession');
        $members->expects($this->never())->method('save');
        $this->expectException(AccessDeniedException::class);

        (new TransferHostHandler($sessions, $members))(new TransferHostCommand($session->getId(), new Uuid(), new Uuid()));
    }

    public function testMissingTargetMemberCannotChangeTheHost(): void
    {
        $host = new Uuid();
        $session = SyncedPartySession::create($host, new Uuid(), null);
        $sessions = $this->createMock(PartySessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($session);
        $sessions->expects($this->never())->method('transferHost');
        $members = $this->createMock(PartyMemberPortInterface::class);
        $members->method('findByUserAndSession')->willReturn(null);
        $members->expects($this->never())->method('save');
        $this->expectException(\RuntimeException::class);

        (new TransferHostHandler($sessions, $members))(new TransferHostCommand($session->getId(), $host, new Uuid()));
    }
}
