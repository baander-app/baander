<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\CreatePartySessionCommand;
use App\Party\Application\CommandHandler\CreatePartySessionHandler;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberJoined;
use App\Party\Domain\Event\PartySessionCreated;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class CreatePartySessionHandlerTest extends TestCase
{
    private PartySessionPortInterface&MockObject $sessionPort;
    private PartyMemberPortInterface&MockObject $memberPort;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private CreatePartySessionHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(fn (object $e) => $e);
        $this->handler = new CreatePartySessionHandler($this->sessionPort, $this->memberPort, $this->eventDispatcher);
    }

    public function testCreatesSessionPromotesHostAndDispatchesEvents(): void
    {
        $hostUserId = Uuid::v4();
        $videoId = Uuid::v4();
        $transcodeJobId = Uuid::v4();

        $session = SyncedPartySession::create($hostUserId, $videoId, $transcodeJobId);
        $member = PartyMember::create($hostUserId, $session->getId());

        $this->sessionPort->expects($this->once())
            ->method('createSession')
            ->with($hostUserId, $videoId, $transcodeJobId, 10)
            ->willReturn($session);

        $this->memberPort->expects($this->once())
            ->method('addMember')
            ->with($hostUserId, $session->getId())
            ->willReturn($member);

        // Save is called after promoteToHost — verify the member is Host before save.
        $this->memberPort->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PartyMember $saved): bool {
                $this->assertSame(MemberRole::Host, $saved->getRole(), 'Member should be promoted to Host before save.');

                return true;
            }));

        // Both PartySessionCreated (with maxMembers) and MemberJoined (with Host role) are dispatched.
        $dispatched = [];
        $this->eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $e) use (&$dispatched): object {
                $dispatched[] = $e;

                return $e;
            });

        $result = ($this->handler)(new CreatePartySessionCommand($hostUserId, $videoId, $transcodeJobId));

        $this->assertSame($session, $result);

        $created = array_filter($dispatched, fn (object $e) => $e instanceof PartySessionCreated);
        $this->assertCount(1, $created, 'PartySessionCreated should be dispatched.');
        $this->assertSame(10, (array_values($created)[0])->getMaxMembers());

        $joined = array_filter($dispatched, fn (object $e) => $e instanceof MemberJoined);
        $this->assertCount(1, $joined, 'MemberJoined should be dispatched.');
        $this->assertSame(MemberRole::Host->value, (array_values($joined)[0])->getRole());
    }

    public function testPassesCustomMaxMembersToSessionAndEvent(): void
    {
        $hostUserId = Uuid::v4();
        $videoId = Uuid::v4();
        $transcodeJobId = Uuid::v4();

        $session = SyncedPartySession::create($hostUserId, $videoId, $transcodeJobId, 20);
        $member = PartyMember::create($hostUserId, $session->getId());

        $this->sessionPort->expects($this->once())
            ->method('createSession')
            ->with($hostUserId, $videoId, $transcodeJobId, 20)
            ->willReturn($session);

        $this->memberPort->method('addMember')->willReturn($member);
        $this->memberPort->method('save');

        $dispatched = [];
        $this->eventDispatcher->method('dispatch')
            ->willReturnCallback(function (object $e) use (&$dispatched): object {
                $dispatched[] = $e;

                return $e;
            });

        ($this->handler)(new CreatePartySessionCommand($hostUserId, $videoId, $transcodeJobId, 20));

        $created = array_filter($dispatched, fn (object $e) => $e instanceof PartySessionCreated);
        $this->assertSame(20, (array_values($created)[0])->getMaxMembers());
    }
}
