<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\LeavePartySessionCommand;
use App\Party\Application\CommandHandler\LeavePartySessionHandler;
use App\Party\Application\Port\PartyMediaAccessPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberLeft;
use App\Party\Domain\Event\PartySessionEnded;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\PartyMemberState;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A leave runs in one transaction that starts by locking the party, and the room hears of it
 * after the commit. The ports here keep a party in memory: a read hands out a copy, so only
 * what the handler saves is stored. The playback authority knows who owns the party's job.
 */
final class LeavePartySessionHandlerTest extends TestCase
{
    private ?SyncedPartySession $session = null;
    /** @var array<string, PartyMember> the stored member rows by user ID */
    private array $members = [];
    /** @var array<string, Uuid> the owner of each transcode job, by job ID */
    private array $jobOwners = [];
    private bool $inTransaction = false;
    private bool $locked = false;
    /** @var list<object> */
    private array $dispatched = [];
    private LeavePartySessionHandler $handler;

    protected function setUp(): void
    {
        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('lockByUuid')->willReturnCallback(function (Uuid $id): ?SyncedPartySession {
            self::assertTrue($this->inTransaction, 'The party is locked inside the transaction.');
            $this->locked = true;

            return $this->session?->getId()->equals($id) === true
                ? SyncedPartySession::reconstitute(clone $this->session->getState())
                : null;
        });
        $sessions->method('save')->willReturnCallback(function (SyncedPartySession $session): void {
            self::assertTrue($this->locked, 'The party changes under its lock.');
            $this->session = $session;
        });

        $members = $this->createStub(PartyMemberPortInterface::class);
        $members->method('removeMember')->willReturnCallback(function (Uuid $userId): bool {
            self::assertTrue($this->locked, 'Membership is decided under the party\'s lock.');
            $held = isset($this->members[$userId->toString()]);
            unset($this->members[$userId->toString()]);

            return $held;
        });
        $members->method('findBySession')->willReturnCallback(function (): array {
            self::assertTrue($this->locked, 'Membership is decided under the party\'s lock.');

            return array_map(
                static fn (PartyMember $member): PartyMember => PartyMember::reconstitute(clone $member->getState()),
                array_values($this->members),
            );
        });
        $members->method('save')->willReturnCallback(function (PartyMember $member): void {
            self::assertTrue($this->locked, 'A member changes under the party\'s lock.');
            $this->members[$member->getUserId()->toString()] = $member;
        });

        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->inTransaction = true;
            try {
                return $operation();
            } finally {
                $this->inTransaction = false;
            }
        });

        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(function (object $event): object {
            self::assertFalse($this->inTransaction, 'The room hears of the leave after the commit.');
            if ($event instanceof MemberLeft) {
                self::assertArrayNotHasKey($event->getUserId()->toString(), $this->members, 'The member row is gone when the room hears of the leave.');
            }
            $this->dispatched[] = $event;

            return $event;
        });

        $media = $this->createStub(PartyMediaAccessPortInterface::class);
        $media->method('ownsTranscodeJob')->willReturnCallback(
            fn (Uuid $userId, Uuid $jobId): bool => ($this->jobOwners[$jobId->toString()] ?? null)?->equals($userId) === true,
        );

        $this->handler = new LeavePartySessionHandler($sessions, $members, $media, $transaction, $events);
    }

    public function testALeaveByAUserWithNoMemberRowChangesNothingAndTellsNobody(): void
    {
        $host = Uuid::v4();
        $this->party($host, guests: [Uuid::v4()]);

        ($this->handler)(new LeavePartySessionCommand(Uuid::v4(), $this->sessionId()));

        self::assertCount(2, $this->members);
        self::assertTrue($this->session?->getHostUserId()->equals($host));
        self::assertTrue($this->session->isActive());
        self::assertSame([], $this->dispatched);
    }

    public function testAMembersLeaveRemovesTheirRowAndTellsTheRoomOnce(): void
    {
        $host = Uuid::v4();
        $guest = Uuid::v4();
        $this->party($host, guests: [$guest]);

        ($this->handler)(new LeavePartySessionCommand($guest, $this->sessionId()));

        self::assertSame([$host->toString() => MemberRole::Host], $this->roles());
        self::assertTrue($this->session?->getHostUserId()->equals($host));
        $this->assertOnlyMemberLeft($guest);
    }

    public function testAHostWhoLeavesHandsThePartyToTheEarliestJoinedMember(): void
    {
        $host = Uuid::v4();
        [$latest, $earliest, $middle] = [Uuid::v4(), Uuid::v4(), Uuid::v4()];
        // Stored out of joining order, so the handler has to compare the joining times.
        $this->party($host, guests: [$latest, $earliest, $middle], joinedAfterMinutes: [3, 1, 2]);

        ($this->handler)(new LeavePartySessionCommand($host, $this->sessionId()));

        self::assertTrue($this->session?->getHostUserId()->equals($earliest), 'The session names the earliest-joined member as its host.');
        self::assertTrue($this->session->isActive());
        self::assertSame(
            [
                $latest->toString() => MemberRole::Member,
                $earliest->toString() => MemberRole::Host,
                $middle->toString() => MemberRole::Member,
            ],
            $this->roles(),
        );
        $this->assertOnlyMemberLeft($host);
    }

    public function testTheLastMemberToLeaveEndsThePartyAfterLeavingIt(): void
    {
        $host = Uuid::v4();
        $this->party($host, guests: []);

        ($this->handler)(new LeavePartySessionCommand($host, $this->sessionId()));

        self::assertSame([], $this->members);
        self::assertFalse($this->session?->isActive(), 'A party without members ends.');
        self::assertSame(PlaybackState::Stopped, $this->session->getPlaybackState());
        self::assertSame([MemberLeft::class, PartySessionEnded::class], array_map(get_class(...), $this->dispatched));
        [$left, $ended] = $this->dispatched;
        self::assertInstanceOf(MemberLeft::class, $left);
        self::assertTrue($left->getUserId()->equals($host));
        self::assertInstanceOf(PartySessionEnded::class, $ended);
        self::assertTrue($ended->getSessionId()->equals($this->sessionId()));
        self::assertTrue($ended->getHostUserId()->equals($host));
    }

    public function testAHostWhoOwnsThePartysJobTakesItAlongWhenLeaving(): void
    {
        $host = Uuid::v4();
        $guest = Uuid::v4();
        $this->party($host, guests: [$guest], job: $this->jobOf($host));

        ($this->handler)(new LeavePartySessionCommand($host, $this->sessionId()));

        self::assertTrue($this->session?->getHostUserId()->equals($guest));
        self::assertNull($this->session->getTranscodeJobId(), 'Nobody left in the party steers the leaver\'s encoder.');
        $this->assertOnlyMemberLeft($host);
    }

    public function testAJobOwnerWhoHandedOnTheHostRoleTakesTheJobAlongWhenLeaving(): void
    {
        $owner = Uuid::v4();
        $newHost = Uuid::v4();
        $this->party($owner, guests: [$newHost], job: $this->jobOf($owner));
        $this->session?->transferHost($newHost);

        ($this->handler)(new LeavePartySessionCommand($owner, $this->sessionId()));

        self::assertTrue($this->session?->getHostUserId()->equals($newHost));
        self::assertNull($this->session->getTranscodeJobId());
        $this->assertOnlyMemberLeft($owner);
    }

    public function testAMemberWhoDoesNotOwnTheJobLeavesItWithTheParty(): void
    {
        $host = Uuid::v4();
        $guest = Uuid::v4();
        $job = $this->jobOf($host);
        $this->party($host, guests: [$guest], job: $job);

        ($this->handler)(new LeavePartySessionCommand($guest, $this->sessionId()));

        self::assertTrue($this->session?->getTranscodeJobId()?->equals($job));
        $this->assertOnlyMemberLeft($guest);
    }

    public function testALeaveOfAPartyThatDoesNotExistDoesNothing(): void
    {
        ($this->handler)(new LeavePartySessionCommand(Uuid::v4(), Uuid::v4()));

        self::assertSame([], $this->dispatched);
    }

    /**
     * Stores a party whose host joined first.
     *
     * @param list<Uuid> $guests
     * @param list<int>  $joinedAfterMinutes each guest's joining time after the host's; one minute apart in the given order when empty
     */
    private function party(Uuid $host, array $guests, array $joinedAfterMinutes = [], ?Uuid $job = null): void
    {
        $this->session = SyncedPartySession::create($host, Uuid::v4(), $job);
        $this->member($host, MemberRole::Host, 0);
        foreach ($guests as $index => $guest) {
            $this->member($guest, MemberRole::Member, $joinedAfterMinutes[$index] ?? $index + 1);
        }
    }

    private function member(Uuid $userId, MemberRole $role, int $joinedAfterMinutes): void
    {
        $this->members[$userId->toString()] = PartyMember::reconstitute(new PartyMemberState(
            id: Uuid::v4(),
            publicId: new PublicId(),
            userId: $userId,
            sessionId: $this->sessionId(),
            joinedAt: new DateTimeImmutable('2026-01-01T10:00:00+00:00')->modify('+' . $joinedAfterMinutes . ' minutes'),
            role: $role,
        ));
    }

    /** @return Uuid a transcode job the user owns */
    private function jobOf(Uuid $owner): Uuid
    {
        $job = Uuid::v4();
        $this->jobOwners[$job->toString()] = $owner;

        return $job;
    }

    private function sessionId(): Uuid
    {
        return $this->session?->getId() ?? self::fail('No party is stored.');
    }

    /** @return array<string, MemberRole> the stored members' roles by user ID */
    private function roles(): array
    {
        return array_map(static fn (PartyMember $member): MemberRole => $member->getRole(), $this->members);
    }

    private function assertOnlyMemberLeft(Uuid $userId): void
    {
        self::assertCount(1, $this->dispatched);
        $event = $this->dispatched[0];
        self::assertInstanceOf(MemberLeft::class, $event);
        self::assertTrue($event->getSessionId()->equals($this->sessionId()));
        self::assertTrue($event->getUserId()->equals($userId));
    }
}
