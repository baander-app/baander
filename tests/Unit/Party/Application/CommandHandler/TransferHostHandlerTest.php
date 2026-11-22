<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\TransferHostCommand;
use App\Party\Application\CommandHandler\TransferHostHandler;
use App\Party\Application\Exception\PartyNotFoundException;
use App\Party\Application\Port\PartyMemberPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A host transfer runs in one transaction that starts by locking the party. The ports here keep
 * a party of a host and a guest in memory: a read hands out a copy, so only what the handler
 * saves is stored. An outsider is a user who is not a member; nobody has the unknown public ID.
 */
final class TransferHostHandlerTest extends TestCase
{
    private const array USERS = ['host', 'guest', 'outsider'];

    private ?SyncedPartySession $session = null;
    /** @var array<string, PartyMember> the stored member rows by user ID */
    private array $members = [];
    /** @var array<string, Uuid> */
    private array $userIds = [];
    /** @var array<string, PublicId> */
    private array $publicIds = [];
    private Uuid $sessionId;
    private bool $inTransaction = false;
    private bool $locked = false;
    private TransferHostHandler $handler;

    protected function setUp(): void
    {
        foreach (self::USERS as $name) {
            $this->userIds[$name] = Uuid::v4();
            $this->publicIds[$name] = new PublicId();
        }
        $this->publicIds['unknown'] = new PublicId();
        $this->session = SyncedPartySession::create($this->userIds['host'], Uuid::v4(), null);
        $this->sessionId = $this->session->getId();
        foreach (['host' => MemberRole::Host, 'guest' => MemberRole::Member] as $name => $role) {
            $this->members[$this->userIds[$name]->toString()] = PartyMember::create($this->userIds[$name], $this->sessionId, $role);
        }

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
        $members->method('findByUserAndSession')->willReturnCallback(function (Uuid $userId): ?PartyMember {
            self::assertTrue($this->locked, 'Membership is decided under the party\'s lock.');
            $member = $this->members[$userId->toString()] ?? null;

            return $member === null ? null : PartyMember::reconstitute(clone $member->getState());
        });
        $members->method('save')->willReturnCallback(function (PartyMember $member): void {
            self::assertTrue($this->locked, 'A member changes under the party\'s lock.');
            $this->members[$member->getUserId()->toString()] = $member;
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

        $this->handler = new TransferHostHandler($sessions, $members, $identities, $transaction);
    }

    public function testTheHostHandsThePartyToAMember(): void
    {
        ($this->handler)($this->transfer(by: 'host', to: 'guest'));

        self::assertTrue($this->session?->getHostUserId()->equals($this->userIds['guest']), 'The session names the new host.');
        self::assertSame(['host' => MemberRole::Member, 'guest' => MemberRole::Host], $this->roles());
    }

    /** @return iterable<string, array{string, string, class-string<\Throwable>, string}> the caller, the named target, the refusal and its message */
    public static function refusals(): iterable
    {
        yield 'the host names themself' => ['host', 'host', InvalidInputException::class, 'The new host already hosts the party.'];
        yield 'the host names a user who is not a member' => ['host', 'outsider', InvalidInputException::class, 'The new host is not a party member.'];
        yield 'the host names an unknown user' => ['host', 'unknown', NotFoundException::class, 'User not found.'];
        yield 'a member who is not the host names a member' => ['guest', 'host', AccessDeniedException::class, 'Only the host may transfer the party.'];
        // Refused before the target is looked up, so the answer does not say whether the user exists.
        yield 'a member who is not the host names an unknown user' => ['guest', 'unknown', AccessDeniedException::class, 'Only the host may transfer the party.'];
    }

    /** @param class-string<\Throwable> $refusal */
    #[DataProvider('refusals')]
    public function testARefusedTransferChangesNeitherTheHostNorTheRoles(string $by, string $to, string $refusal, string $message): void
    {
        $refused = $this->refusalOf($this->transfer($by, $to));

        self::assertSame($refusal, $refused::class);
        self::assertSame($message, $refused->getMessage());
        self::assertTrue($this->session?->getHostUserId()->equals($this->userIds['host']), 'The session still names its host.');
        self::assertSame(['host' => MemberRole::Host, 'guest' => MemberRole::Member], $this->roles());
    }

    public function testATransferOfAPartyThatDoesNotExistAnswersAsAnyMissingResource(): void
    {
        $this->session = null;

        $refused = $this->refusalOf($this->transfer(by: 'host', to: 'guest'));

        // The shared not-found outcome, translated with the key a route's notFound() uses, so a
        // refused caller reads exactly as for a missing party in every request language.
        self::assertInstanceOf(NotFoundException::class, $refused);
        self::assertInstanceOf(TranslatableInterface::class, $refused);
        self::assertSame('errors.not_found.resource', $refused->trans(new IdentityTranslator()));
        self::assertSame(PartyNotFoundException::class, $refused::class);
        self::assertSame('Resource not found.', $refused->getMessage());
    }

    private function transfer(string $by, string $to): TransferHostCommand
    {
        return new TransferHostCommand($this->sessionId, $this->userIds[$by], $this->publicIds[$to]);
    }

    private function refusalOf(TransferHostCommand $command): \Throwable
    {
        try {
            ($this->handler)($command);
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (\Throwable $refused) {
            return $refused;
        }

        self::fail('The transfer was not refused.');
    }

    /** @return array<string, MemberRole> the stored member rows' roles by user name */
    private function roles(): array
    {
        $roles = [];
        foreach (self::USERS as $name) {
            $member = $this->members[$this->userIds[$name]->toString()] ?? null;
            if ($member !== null) {
                $roles[$name] = $member->getRole();
            }
        }

        return $roles;
    }
}
