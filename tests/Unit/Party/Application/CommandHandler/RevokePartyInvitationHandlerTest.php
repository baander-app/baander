<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\RevokePartyInvitationCommand;
use App\Party\Application\CommandHandler\RevokePartyInvitationHandler;
use App\Party\Application\Exception\PartyNotFoundException;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyMediaAccessPortInterface;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Event\MemberLeft;
use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A revoke runs in one transaction that starts by locking the party, and the room hears of a
 * removed member after the commit. The ports here keep in memory which users hold an invitation
 * and which hold a member row; the host holds both. Nobody has the unknown public ID. The
 * playback authority knows who owns the party's job.
 */
final class RevokePartyInvitationHandlerTest extends TestCase
{
    private const array USERS = ['host', 'guest', 'other'];

    private ?SyncedPartySession $session = null;
    /** @var array<string, true> the invited users, by user ID */
    private array $invited = [];
    /** @var array<string, true> the users who hold a member row, by user ID */
    private array $members = [];
    /** @var array<string, Uuid> the owner of each transcode job, by job ID */
    private array $jobOwners = [];
    /** @var array<string, Uuid> */
    private array $userIds = [];
    /** @var array<string, PublicId> */
    private array $publicIds = [];
    private Uuid $sessionId;
    private bool $inTransaction = false;
    private bool $locked = false;
    /** @var list<object> */
    private array $dispatched = [];
    private RevokePartyInvitationHandler $handler;

    protected function setUp(): void
    {
        foreach (self::USERS as $name) {
            $this->userIds[$name] = Uuid::v4();
            $this->publicIds[$name] = new PublicId();
        }
        $this->publicIds['unknown'] = new PublicId();
        $this->session = SyncedPartySession::create($this->userIds['host'], Uuid::v4(), null);
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
        $sessions->method('save')->willReturnCallback(function (SyncedPartySession $session): void {
            self::assertTrue($this->locked, 'The party changes under its lock.');
            $this->session = $session;
        });

        $invitations =$this->createStub(PartyInvitationPortInterface::class);
        $invitations->method('revoke')->willReturnCallback(function (Uuid $userId, Uuid $sessionId): bool {
            self::assertTrue($this->locked, 'An invitation is deleted under the party\'s lock.');
            self::assertTrue($sessionId->equals($this->sessionId));

            return $this->take($this->invited, $userId);
        });

        $members = $this->createStub(PartyMemberPortInterface::class);
        $members->method('removeMember')->willReturnCallback(function (Uuid $userId, Uuid $sessionId): bool {
            self::assertTrue($this->locked, 'Membership is decided under the party\'s lock.');
            self::assertTrue($sessionId->equals($this->sessionId));

            return $this->take($this->members, $userId);
        });

        $identities = $this->createStub(PartyPublicIdentityPortInterface::class);
        $identities->method('userId')->willReturnCallback(function (PublicId $publicId): Uuid {
            foreach (self::USERS as $name) {
                if ($this->publicIds[$name]->equals($publicId)) {
                    return $this->userIds[$name];
                }
            }

            throw new NotFoundException('User not found.');
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
            self::assertFalse($this->inTransaction, 'The room hears of the removed member after the commit.');
            $this->dispatched[] = $event;

            return $event;
        });

        $media = $this->createStub(PartyMediaAccessPortInterface::class);
        $media->method('ownsTranscodeJob')->willReturnCallback(
            fn (Uuid $userId, Uuid $jobId): bool => ($this->jobOwners[$jobId->toString()] ?? null)?->equals($userId) === true,
        );

        $this->handler = new RevokePartyInvitationHandler($sessions, $invitations, $members, $media, $identities, $transaction, $events);
    }

    /** @return iterable<string, array{bool, bool}> whether the user holds an invitation and whether they hold a member row */
    public static function standings(): iterable
    {
        yield 'never invited' => [false, false];
        yield 'invited, not joined' => [true, false];
        yield 'invited and joined' => [true, true];
        yield 'joined without an invitation' => [false, true];
    }

    #[DataProvider('standings')]
    public function testARevokedUserHoldsNeitherAnInvitationNorAMemberRowAndTheRoomHearsOnlyOfARemovedMember(bool $invited, bool $joined): void
    {
        $this->stand('guest', $invited, $joined);
        $this->stand('other', invited: true, joined: true);

        ($this->handler)($this->revoke(by: 'host', user: 'guest'));

        self::assertSame(['host', 'other'], $this->names($this->invited));
        self::assertSame(['host', 'other'], $this->names($this->members));
        if (!$joined) {
            self::assertSame([], $this->dispatched);

            return;
        }
        self::assertCount(1, $this->dispatched);
        $left = $this->dispatched[0];
        self::assertInstanceOf(MemberLeft::class, $left);
        self::assertTrue($left->getSessionId()->equals($this->sessionId));
        self::assertTrue($left->getUserId()->equals($this->userIds['guest']));
    }

    public function testRevokingTheJobsOwnerTakesTheJobFromTheParty(): void
    {
        // The guest created the party with their own job and handed the host role on.
        $this->partyWithAJobOf('guest');
        $this->stand('guest', invited: true, joined: true);

        ($this->handler)($this->revoke(by: 'host', user: 'guest'));

        self::assertNull($this->session?->getTranscodeJobId(), 'Nobody left in the party steers the removed member\'s encoder.');
    }

    public function testRevokingAMemberWhoDoesNotOwnTheJobKeepsItWithTheParty(): void
    {
        $job = $this->partyWithAJobOf('host');
        $this->stand('guest', invited: true, joined: true);

        ($this->handler)($this->revoke(by: 'host', user: 'guest'));

        self::assertTrue($this->session?->getTranscodeJobId()?->equals($job));
    }

    /** @return iterable<string, array{string, string, class-string<\Throwable>, string}> the caller, the named user, the refusal and its message */
    public static function refusals(): iterable
    {
        yield 'the host names themself' => ['host', 'host', InvalidInputException::class, 'The host cannot revoke their own invitation.'];
        yield 'the host names an unknown user' => ['host', 'unknown', NotFoundException::class, 'User not found.'];
        yield 'a user who is not the host names a member' => ['guest', 'other', AccessDeniedException::class, 'Only the host may manage the party\'s invitations.'];
        // Refused before the user is looked up, so the answer does not say whether the user exists.
        yield 'a user who is not the host names an unknown user' => ['guest', 'unknown', AccessDeniedException::class, 'Only the host may manage the party\'s invitations.'];
    }

    /** @param class-string<\Throwable> $refusal */
    #[DataProvider('refusals')]
    public function testARefusedRevokeRemovesNothingAndTellsNobody(string $by, string $user, string $refusal, string $message): void
    {
        $this->stand('guest', invited: true, joined: true);
        $this->stand('other', invited: true, joined: true);

        $refused = $this->refusalOf($this->revoke($by, $user));

        self::assertSame($refusal, $refused::class);
        self::assertSame($message, $refused->getMessage());
        self::assertSame(self::USERS, $this->names($this->invited));
        self::assertSame(self::USERS, $this->names($this->members));
        self::assertSame([], $this->dispatched);
    }

    public function testARevokeInAPartyThatDoesNotExistAnswersAsAnyMissingResource(): void
    {
        $this->stand('guest', invited: true, joined: true);
        $this->session = null;

        $refused = $this->refusalOf($this->revoke(by: 'host', user: 'guest'));

        // The shared not-found outcome, translated with the key a route's notFound() uses, so a
        // refused caller reads exactly as for a missing party in every request language.
        self::assertInstanceOf(NotFoundException::class, $refused);
        self::assertInstanceOf(TranslatableInterface::class, $refused);
        self::assertSame('errors.not_found.resource', $refused->trans(new IdentityTranslator()));
        self::assertSame(PartyNotFoundException::class, $refused::class);
        self::assertSame('Resource not found.', $refused->getMessage());
        self::assertSame(['host', 'guest'], $this->names($this->invited));
        self::assertSame(['host', 'guest'], $this->names($this->members));
        self::assertSame([], $this->dispatched);
    }

    private function revoke(string $by, string $user): RevokePartyInvitationCommand
    {
        return new RevokePartyInvitationCommand($this->sessionId, $this->userIds[$by], $this->publicIds[$user]);
    }

    private function refusalOf(RevokePartyInvitationCommand $command): \Throwable
    {
        try {
            ($this->handler)($command);
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (\Throwable $refused) {
            return $refused;
        }

        self::fail('The revoke was not refused.');
    }

    /**
     * Replaces the party with one the host leads and whose transcode job the named user owns.
     *
     * @return Uuid the job
     */
    private function partyWithAJobOf(string $owner): Uuid
    {
        $job = Uuid::v4();
        $this->jobOwners[$job->toString()] = $this->userIds[$owner];
        $this->session = SyncedPartySession::create($this->userIds['host'], Uuid::v4(), $job);
        $this->sessionId = $this->session->getId();

        return $job;
    }

    /** Stores whether the named user holds an invitation and a member row. */
    private function stand(string $name, bool $invited, bool $joined): void
    {
        $id = $this->userIds[$name]->toString();
        if ($invited) {
            $this->invited[$id] = true;
        }
        if ($joined) {
            $this->members[$id] = true;
        }
    }

    /**
     * Deletes the user's row and says whether there was one.
     *
     * @param array<string, true> $rows
     */
    private function take(array &$rows, Uuid $userId): bool
    {
        $held = isset($rows[$userId->toString()]);
        unset($rows[$userId->toString()]);

        return $held;
    }

    /**
     * @param array<string, true> $rows
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
