<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\JoinPartySessionCommand;
use App\Party\Application\CommandHandler\JoinPartySessionHandler;
use App\Party\Application\Exception\PartyNotFoundException;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberJoined;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\PartyMemberState;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A join is decided in one transaction that starts by locking the party: a member row or an
 * invitation admits, and a user who holds neither is answered as for a party that does not exist,
 * whatever state the party is in. The ports here keep in memory which users hold an invitation
 * and which hold a member row. The party has room for three, and its host holds both.
 */
final class JoinPartySessionHandlerTest extends TestCase
{
    private const array USERS = ['host', 'guest', 'other'];

    private ?SyncedPartySession $session = null;
    /** @var array<string, true> the invited users, by user ID */
    private array $invited = [];
    /** @var array<string, PartyMember> the stored member rows, by user ID */
    private array $members = [];
    /** @var array<string, Uuid> */
    private array $userIds = [];
    private Uuid $sessionId;
    private bool $inTransaction = false;
    private bool $locked = false;
    /** @var list<object> */
    private array $dispatched = [];
    private JoinPartySessionHandler $handler;

    protected function setUp(): void
    {
        foreach (self::USERS as $name) {
            $this->userIds[$name] = Uuid::v4();
        }
        $this->session = SyncedPartySession::create($this->userIds['host'], Uuid::v4(), null, maxMembers: 3);
        $this->sessionId = $this->session->getId();
        $this->stand('host', invited: true, joined: true);

        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('lockByUuid')->willReturnCallback(function (Uuid $id): ?SyncedPartySession {
            self::assertTrue($this->inTransaction, 'The party is locked inside the transaction.');
            $this->locked = true;

            return $this->session?->getId()->equals($id) === true
                ? SyncedPartySession::reconstitute(clone $this->session->getState())
                : null;
        });

        $invitations = $this->createStub(PartyInvitationPortInterface::class);
        $invitations->method('isInvited')->willReturnCallback(function (Uuid $userId, Uuid $sessionId): bool {
            self::assertTrue($this->locked, 'The invitation is read under the party\'s lock.');
            self::assertTrue($sessionId->equals($this->sessionId));

            return isset($this->invited[$userId->toString()]);
        });

        $members = $this->createStub(PartyMemberPortInterface::class);
        $members->method('findByUserAndSession')->willReturnCallback(function (Uuid $userId, Uuid $sessionId): ?PartyMember {
            self::assertTrue($this->locked, 'Membership is decided under the party\'s lock.');
            self::assertTrue($sessionId->equals($this->sessionId));
            $stored = $this->members[$userId->toString()] ?? null;

            return $stored === null ? null : PartyMember::reconstitute(clone $stored->getState());
        });
        $members->method('countBySession')->willReturnCallback(function (Uuid $sessionId): int {
            self::assertTrue($this->locked, 'The party\'s members are counted under its lock.');
            self::assertTrue($sessionId->equals($this->sessionId));

            return count($this->members);
        });
        $members->method('addMember')->willReturnCallback(function (Uuid $userId, Uuid $sessionId): PartyMember {
            self::assertTrue($this->locked, 'A member row is written under the party\'s lock.');
            self::assertArrayNotHasKey($userId->toString(), $this->members, 'A user holds one member row in a party.');

            return $this->members[$userId->toString()] = PartyMember::create($userId, $sessionId);
        });
        $members->method('save')->willReturnCallback(function (PartyMember $member): void {
            self::assertTrue($this->locked, 'A member row is written under the party\'s lock.');
            $this->members[$member->getUserId()->toString()] = PartyMember::reconstitute(clone $member->getState());
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
            self::assertFalse($this->inTransaction, 'The room hears of the new member after the commit.');
            $this->dispatched[] = $event;

            return $event;
        });

        $this->handler = new JoinPartySessionHandler($sessions, $members, $invitations, $transaction, $events);
    }

    public function testAnInvitedUserBecomesAMemberAndTheRoomHearsOfItAfterTheCommit(): void
    {
        $this->stand('guest', invited: true, joined: false);

        $member = ($this->handler)($this->join('guest'));

        self::assertTrue($member->getUserId()->equals($this->userIds['guest']));
        self::assertTrue($member->getSessionId()->equals($this->sessionId));
        self::assertSame(MemberRole::Member, $member->getRole());
        self::assertTrue($member->isConnected());
        self::assertSame(['host', 'guest'], $this->names($this->members));
        self::assertTrue($this->members[$this->userIds['guest']->toString()]->getId()->equals($member->getId()));

        self::assertCount(1, $this->dispatched);
        $joined = $this->dispatched[0];
        self::assertInstanceOf(MemberJoined::class, $joined);
        self::assertTrue($joined->getSessionId()->equals($this->sessionId));
        self::assertTrue($joined->getUserId()->equals($this->userIds['guest']));
        self::assertSame(MemberRole::Member->value, $joined->getRole());
    }

    public function testAnInvitedUserTakesTheLastPlaceInTheParty(): void
    {
        $this->stand('other', invited: true, joined: true);
        $this->stand('guest', invited: true, joined: false);

        ($this->handler)($this->join('guest'));

        self::assertSame(self::USERS, $this->names($this->members));
        self::assertCount(1, $this->dispatched);
    }

    /** @return iterable<string, array{bool, bool}> whether the member still holds an invitation and whether the party is full */
    public static function returningMembers(): iterable
    {
        yield 'a member who is invited' => [true, false];
        // The member row alone admits: it is what the join wrote.
        yield 'a member who holds no invitation' => [false, false];
        // A returning member already holds a place.
        yield 'a member of a full party' => [true, true];
        yield 'a member of a full party who holds no invitation' => [false, true];
    }

    #[DataProvider('returningMembers')]
    public function testAMemberReconnectsToTheirOwnMemberRowAndIsNotAnnouncedAgain(bool $invited, bool $full): void
    {
        $this->stand('guest', $invited, joined: true, connected: false);
        if ($full) {
            $this->stand('other', invited: true, joined: true);
        }
        $row = $this->members[$this->userIds['guest']->toString()];
        $before = $this->names($this->members);

        $member = ($this->handler)($this->join('guest'));

        self::assertTrue($member->getId()->equals($row->getId()));
        self::assertTrue($member->isConnected());
        self::assertTrue($this->members[$this->userIds['guest']->toString()]->isConnected(), 'The stored member row is connected again.');
        self::assertSame($before, $this->names($this->members));
        self::assertSame([], $this->dispatched);
    }

    /** @return iterable<string, array{bool, bool, bool}> whether the party exists, whether it has ended and whether it is full */
    public static function partiesHiddenFromAnUninvitedUser(): iterable
    {
        yield 'a party that does not exist' => [false, false, false];
        yield 'an active party' => [true, false, false];
        // Ended and full are looked at only for a user the party admits.
        yield 'an ended party' => [true, true, false];
        yield 'a full party' => [true, false, true];
        yield 'an ended and full party' => [true, true, true];
    }

    #[DataProvider('partiesHiddenFromAnUninvitedUser')]
    public function testAUserWithNeitherAMemberRowNorAnInvitationIsAnsweredAsForAPartyThatDoesNotExist(bool $exists, bool $ended, bool $full): void
    {
        $this->stand('other', invited: true, joined: $full);
        if ($full) {
            // The host, the other member and one more user fill the three places.
            $this->members[Uuid::v4()->toString()] = PartyMember::create(Uuid::v4(), $this->sessionId);
        }
        if ($ended) {
            $this->session?->endSession();
        }
        if (!$exists) {
            $this->session = null;
        }
        $before = array_keys($this->members);

        $refused = $this->refusalOf($this->join('guest'));

        // The shared not-found outcome, translated with the key a route's notFound() uses, so a
        // refused caller reads exactly as for a missing party in every request language.
        self::assertInstanceOf(NotFoundException::class, $refused);
        self::assertInstanceOf(TranslatableInterface::class, $refused);
        self::assertSame('errors.not_found.resource', $refused->trans(new IdentityTranslator()));
        self::assertSame(PartyNotFoundException::class, $refused::class);
        self::assertSame('Resource not found.', $refused->getMessage());
        self::assertSame($before, array_keys($this->members));
        self::assertSame([], $this->dispatched);
    }

    /** @return iterable<string, array{bool, bool, bool, string}> whether the user holds a member row, whether the party has ended, whether it is full, and the refusal's message */
    public static function partiesThatRefuseAnAdmittedUser(): iterable
    {
        yield 'a member returning to an ended party' => [true, true, false, 'The party has ended.'];
        yield 'an invited user joining an ended party' => [false, true, false, 'The party has ended.'];
        yield 'an invited user joining a full party' => [false, false, true, 'The party is full.'];
        yield 'an invited user joining an ended and full party' => [false, true, true, 'The party has ended.'];
    }

    #[DataProvider('partiesThatRefuseAnAdmittedUser')]
    public function testAnEndedPartyRefusesItsMembersAndInviteesAndAFullOneItsInvitees(bool $joined, bool $ended, bool $full, string $message): void
    {
        $this->stand('guest', invited: true, joined: $joined, connected: false);
        $this->stand('other', invited: true, joined: true);
        if ($full) {
            $this->members[Uuid::v4()->toString()] = PartyMember::create(Uuid::v4(), $this->sessionId);
        }
        if ($ended) {
            $this->session?->endSession();
        }
        $before = array_keys($this->members);

        $refused = $this->refusalOf($this->join('guest'));

        self::assertSame(ConflictException::class, $refused::class);
        self::assertSame($message, $refused->getMessage());
        self::assertSame($before, array_keys($this->members));
        if ($joined) {
            self::assertFalse($this->members[$this->userIds['guest']->toString()]->isConnected(), 'A refused member is not reconnected.');
        }
        self::assertSame([], $this->dispatched);
    }

    private function join(string $user): JoinPartySessionCommand
    {
        return new JoinPartySessionCommand($this->userIds[$user], $this->sessionId);
    }

    private function refusalOf(JoinPartySessionCommand $command): \Throwable
    {
        try {
            ($this->handler)($command);
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (\Throwable $refused) {
            return $refused;
        }

        self::fail('The join was not refused.');
    }

    /** Stores whether the named user holds an invitation and a member row, and whether that row is connected. */
    private function stand(string $name, bool $invited, bool $joined, bool $connected = true): void
    {
        $id = $this->userIds[$name];
        if ($invited) {
            $this->invited[$id->toString()] = true;
        }
        if ($joined) {
            $this->members[$id->toString()] = PartyMember::reconstitute(new PartyMemberState(
                id: Uuid::v4(),
                publicId: new PublicId(),
                userId: $id,
                sessionId: $this->sessionId,
                joinedAt: new DateTimeImmutable('-1 hour'),
                role: $name === 'host' ? MemberRole::Host : MemberRole::Member,
                isConnected: $connected,
            ));
        }
    }

    /**
     * @param array<string, PartyMember> $rows
     *
     * @return list<string> the names of the users who hold a row, in the order of USERS
     */
    private function names(array $rows): array
    {
        return array_values(array_filter(
            self::USERS,
            fn (string $name): bool => isset($rows[$this->userIds[$name]->toString()]),
        ));
    }
}
