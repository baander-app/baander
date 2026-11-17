<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\CreatePartySessionCommand;
use App\Party\Application\Exception\PartyMediaNotFoundException;
use App\Party\Application\Exception\TranscodeJobVideoMismatchException;
use App\Party\Application\CommandHandler\CreatePartySessionHandler;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyMediaAccessPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberJoined;
use App\Party\Domain\Event\PartySessionCreated;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class CreatePartySessionHandlerTest extends TestCase
{
    private PartySessionPortInterface&Stub $sessionPort;
    private PartyMemberPortInterface&Stub $memberPort;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private PartyMediaAccessPortInterface&Stub $mediaAccess;
    private PartyInvitationPortInterface&Stub $invitationPort;
    private TransactionPortInterface&Stub $transaction;
    private bool $inTransaction = false;
    private int $transactions = 0;
    private CreatePartySessionHandler $handler;

    protected function setUp(): void
    {
        $this->sessionPort = $this->createStub(PartySessionPortInterface::class);
        $this->memberPort = $this->createStub(PartyMemberPortInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnCallback(fn (object $e) => $e);
        $this->mediaAccess = $this->createStub(PartyMediaAccessPortInterface::class);
        $this->invitationPort = $this->createStub(PartyInvitationPortInterface::class);
        $this->transaction = $this->createStub(TransactionPortInterface::class);
        $this->transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            ++$this->transactions;
            $this->inTransaction = true;
            try {
                return $operation();
            } finally {
                $this->inTransaction = false;
            }
        });
        $this->handler = $this->createCreatePartySessionHandlerFixture();
    }

    private function createCreatePartySessionHandlerFixture(): CreatePartySessionHandler
    {
        $fixture = new CreatePartySessionHandler(
            $this->sessionPort,
            $this->memberPort,
            $this->eventDispatcher,
            $this->mediaAccess,
            $this->invitationPort,
            $this->transaction,
        );
        return $fixture;
    }

    public function testCreatesSessionPromotesHostAndDispatchesEvents(): void
    {
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->handler = $this->createCreatePartySessionHandlerFixture();

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
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->handler = $this->createCreatePartySessionHandlerFixture();

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
        $this->eventDispatcher->expects($this->exactly(2))->method('dispatch')
            ->willReturnCallback(function (object $e) use (&$dispatched): object {
                $dispatched[] = $e;

                return $e;
            });

        ($this->handler)(new CreatePartySessionCommand($hostUserId, $videoId, $transcodeJobId, 20));

        $created = array_filter($dispatched, fn (object $e) => $e instanceof PartySessionCreated);
        $this->assertSame(20, (array_values($created)[0])->getMaxMembers());
    }

    public function testCreatesASessionWithoutATranscodeJob(): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->mediaAccess = $this->createMock(PartyMediaAccessPortInterface::class);
        $this->handler = $this->createCreatePartySessionHandlerFixture();
        $hostUserId = Uuid::v4();
        $videoId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, $videoId, null);

        $this->mediaAccess->expects($this->once())->method('assertHostCanPlay')->with($videoId, null);
        $this->sessionPort->expects($this->once())->method('createSession')->with($hostUserId, $videoId, null, 10)->willReturn($session);
        $this->memberPort->method('addMember')->willReturn(PartyMember::create($hostUserId, $session->getId()));
        $this->eventDispatcher->expects($this->exactly(2))->method('dispatch')->willReturnArgument(0);

        self::assertNull(($this->handler)(new CreatePartySessionCommand($hostUserId, $videoId, null))->getTranscodeJobId());
    }

    public function testTheCreatorHoldsAnInvitationWrittenWithTheSessionAndTheMemberRowBeforeAnyEventLeaves(): void
    {
        $hostUserId = Uuid::v4();
        $session = SyncedPartySession::create($hostUserId, Uuid::v4(), null);
        /** @var list<string> $written what was stored */
        $written = [];
        /** @var list<array{string, string}> $invited the invited user and the session they are invited to */
        $invited = [];
        $inOneTransaction = function (string $what) use (&$written): void {
            self::assertTrue($this->inTransaction, 'The ' . $what . ' is written inside the transaction.');
            $written[] = $what;
        };

        $this->sessionPort->method('createSession')->willReturnCallback(static function () use ($session, $inOneTransaction): SyncedPartySession {
            $inOneTransaction('session');

            return $session;
        });
        $this->memberPort->method('addMember')->willReturn(PartyMember::create($hostUserId, $session->getId()));
        $this->memberPort->method('save')->willReturnCallback(static function (PartyMember $member) use ($inOneTransaction): void {
            $inOneTransaction('host member row');
        });
        $this->invitationPort->method('invite')->willReturnCallback(static function (Uuid $userId, Uuid $sessionId) use (&$invited, $inOneTransaction): void {
            $inOneTransaction('invitation');
            $invited[] = [$userId->toString(), $sessionId->toString()];
        });
        $dispatched = [];
        $this->eventDispatcher->expects($this->exactly(2))->method('dispatch')->willReturnCallback(function (object $event) use (&$dispatched, &$invited): object {
            self::assertFalse($this->inTransaction, 'The events leave after the commit.');
            self::assertCount(1, $invited, 'The creator is invited before any event leaves.');
            $dispatched[] = $event::class;

            return $event;
        });

        ($this->handler)(new CreatePartySessionCommand($hostUserId, $session->getVideoId(), null));

        self::assertSame([[$hostUserId->toString(), $session->getId()->toString()]], $invited);
        self::assertEqualsCanonicalizing(['session', 'host member row', 'invitation'], $written);
        self::assertSame(1, $this->transactions, 'The session, the member row and the invitation share one transaction.');
        self::assertSame([PartySessionCreated::class, MemberJoined::class], $dispatched);
    }

    /** @return iterable<string, array{\RuntimeException}> */
    public static function rejectedMedia(): iterable
    {
        yield 'missing or inaccessible video' => [PartyMediaNotFoundException::video()];
        yield 'missing job' => [PartyMediaNotFoundException::transcodeJob()];
        yield 'job of another video' => [new TranscodeJobVideoMismatchException()];
    }

    #[DataProvider('rejectedMedia')]
    public function testRejectedMediaCreatesNothing(\RuntimeException $rejection): void
    {
        $this->sessionPort = $this->createMock(PartySessionPortInterface::class);
        $this->memberPort = $this->createMock(PartyMemberPortInterface::class);
        $this->invitationPort = $this->createMock(PartyInvitationPortInterface::class);
        $this->handler = $this->createCreatePartySessionHandlerFixture();
        $this->mediaAccess->method('assertHostCanPlay')->willThrowException($rejection);

        $this->sessionPort->expects($this->never())->method('createSession');
        $this->memberPort->expects($this->never())->method('addMember');
        $this->invitationPort->expects($this->never())->method('invite');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectExceptionObject($rejection);
        ($this->handler)(new CreatePartySessionCommand(Uuid::v4(), Uuid::v4(), Uuid::v4()));
    }
}
