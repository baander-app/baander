<?php

declare(strict_types=1);

namespace App\Tests\Unit\Party\Application\CommandHandler;

use App\Party\Application\Command\InvitePartyMemberCommand;
use App\Party\Application\CommandHandler\InvitePartyMemberHandler;
use App\Party\Application\Exception\PartyNotFoundException;
use App\Party\Application\Port\PartyInvitationPortInterface;
use App\Party\Application\Port\PartyPublicIdentityPortInterface;
use App\Party\Application\Port\PartySessionPortInterface;
use App\Party\Domain\Model\SyncedPartySession;
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
 * An invitation is written in one transaction that starts by locking the party. The ports here
 * keep a party in memory whose host holds the only invitation. The guest is a user who is not
 * the host; nobody has the unknown public ID.
 */
final class InvitePartyMemberHandlerTest extends TestCase
{
    private const array USERS = ['host', 'guest', 'outsider'];

    private ?SyncedPartySession $session = null;
    /** @var list<string> the invited users' IDs */
    private array $invited = [];
    /** @var array<string, Uuid> */
    private array $userIds = [];
    /** @var array<string, PublicId> */
    private array $publicIds = [];
    private Uuid $sessionId;
    private bool $inTransaction = false;
    private bool $locked = false;
    private InvitePartyMemberHandler $handler;

    protected function setUp(): void
    {
        foreach (self::USERS as $name) {
            $this->userIds[$name] = Uuid::v4();
            $this->publicIds[$name] = new PublicId();
        }
        $this->publicIds['unknown'] = new PublicId();
        $this->session = SyncedPartySession::create($this->userIds['host'], Uuid::v4(), null);
        $this->sessionId = $this->session->getId();
        $this->invited = [$this->userIds['host']->toString()];

        $sessions = $this->createStub(PartySessionPortInterface::class);
        $sessions->method('lockByUuid')->willReturnCallback(function (Uuid $id): ?SyncedPartySession {
            self::assertTrue($this->inTransaction, 'The party is locked inside the transaction.');
            $this->locked = true;

            return $this->session?->getId()->equals($id) === true
                ? SyncedPartySession::reconstitute(clone $this->session->getState())
                : null;
        });

        $invitations = $this->createStub(PartyInvitationPortInterface::class);
        $invitations->method('invite')->willReturnCallback(function (Uuid $userId, Uuid $sessionId): void {
            self::assertTrue($this->locked, 'An invitation is written under the party\'s lock.');
            self::assertTrue($sessionId->equals($this->sessionId));
            $this->invited[] = $userId->toString();
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

        $this->handler = new InvitePartyMemberHandler($sessions, $invitations, $identities, $transaction);
    }

    public function testTheHostInvitesAUser(): void
    {
        ($this->handler)($this->invite(by: 'host', user: 'outsider'));

        self::assertSame([$this->userIds['host']->toString(), $this->userIds['outsider']->toString()], $this->invited);
    }

    /** @return iterable<string, array{string, string, class-string<\Throwable>, string}> the caller, the named user, the refusal and its message */
    public static function refusals(): iterable
    {
        yield 'the host names themself' => ['host', 'host', InvalidInputException::class, 'The host cannot invite themself.'];
        yield 'the host names an unknown user' => ['host', 'unknown', NotFoundException::class, 'User not found.'];
        yield 'a user who is not the host names a user' => ['guest', 'outsider', AccessDeniedException::class, 'Only the host may manage the party\'s invitations.'];
        // Refused before the user is looked up, so the answer does not say whether the user exists.
        yield 'a user who is not the host names an unknown user' => ['guest', 'unknown', AccessDeniedException::class, 'Only the host may manage the party\'s invitations.'];
    }

    /** @param class-string<\Throwable> $refusal */
    #[DataProvider('refusals')]
    public function testARefusedInviteInvitesNobody(string $by, string $user, string $refusal, string $message): void
    {
        $refused = $this->refusalOf($this->invite($by, $user));

        self::assertSame($refusal, $refused::class);
        self::assertSame($message, $refused->getMessage());
        self::assertSame([$this->userIds['host']->toString()], $this->invited);
    }

    public function testAnInviteToAPartyThatDoesNotExistAnswersAsAnyMissingResource(): void
    {
        $this->session = null;

        $refused = $this->refusalOf($this->invite(by: 'host', user: 'outsider'));

        // The shared not-found outcome, translated with the key a route's notFound() uses, so a
        // refused caller reads exactly as for a missing party in every request language.
        self::assertInstanceOf(NotFoundException::class, $refused);
        self::assertInstanceOf(TranslatableInterface::class, $refused);
        self::assertSame('errors.not_found.resource', $refused->trans(new IdentityTranslator()));
        self::assertSame(PartyNotFoundException::class, $refused::class);
        self::assertSame('Resource not found.', $refused->getMessage());
        self::assertSame([$this->userIds['host']->toString()], $this->invited);
    }

    private function invite(string $by, string $user): InvitePartyMemberCommand
    {
        return new InvitePartyMemberCommand($this->sessionId, $this->userIds[$by], $this->publicIds[$user]);
    }

    private function refusalOf(InvitePartyMemberCommand $command): \Throwable
    {
        try {
            ($this->handler)($command);
        } catch (AssertionFailedError $failure) {
            throw $failure;
        } catch (\Throwable $refused) {
            return $refused;
        }

        self::fail('The invite was not refused.');
    }
}
