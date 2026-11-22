<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\VerifyEmailCommand;
use App\Auth\Application\CommandHandler\User\VerifyEmailHandler;
use App\Auth\Application\Exception\EmailVerificationException;
use App\Auth\Application\Exception\VerificationLinkOtherAccountException;
use App\Auth\Application\Port\EmailChangeNoticeInterface;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Service\ChainConnectionCloser;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Domain\Event\EmailVerified;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class VerifyEmailHandlerTest extends TestCase
{
    private bool $active = false;
    private ?User $user = null;
    /** @var array<string, array{owner: Uuid, email: Email, live: bool}> outstanding tokens by raw value */
    private array $tokens = [];
    /** @var list<bool> whether each save ran inside the transaction */
    private array $saves = [];
    /** @var list<object> */
    private array $events = [];
    private ?\Throwable $eventFailure = null;
    /** @var list<string> addresses other accounts use */
    private array $taken = [];
    /** @var list<array{string, string, bool}> name, previous address, inside the transaction */
    private array $notices = [];
    private ?AccessToken $requestingSession = null;
    /** @var list<string> the session each revocation kept, or "none" */
    private array $keptSessions = [];
    /** @var list<array{list<string>, bool}> the access token ids of each close, and whether it ran inside the transaction */
    private array $closes = [];

    public function testVerifiesTheAddressTheTokenWasIssuedFor(): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $this->issue('raw-token', $this->user, 'alice@baander.app');

        $emailChanged = ($this->handler())($this->command(' raw-token '));

        $this->assertFalse($emailChanged);
        $this->assertSame([], $this->tokens, 'The token is used up.');
        $this->assertTrue($this->user->isEmailVerified());
        $this->assertSame([true], $this->saves);
        $this->assertCount(1, $this->events);
        $this->assertInstanceOf(EmailVerified::class, $this->events[0]);
        $this->assertSame([], $this->keptSessions, 'Verifying the registered address ends no session.');
    }

    public function testAnAlreadyVerifiedAddressSucceedsWithoutASecondEvent(): void
    {
        $this->user = User::createByOperator(new Email('alice@baander.app'), 'hashed-pw', 'Alice', ['ROLE_USER']);
        $this->issue('raw-token', $this->user, 'alice@baander.app');

        ($this->handler())($this->command('raw-token'));

        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->events);
    }

    /** Another account's live link is refused apart from every unusable token, and stays usable. */
    public function testAnotherAccountsLinkIsRefusedAndKeptForItsAccount(): void
    {
        $owner = User::register(new Email('bob@baander.app'), 'hashed-pw', 'Bob');
        $this->user = User::createByOperator(new Email('alice@baander.app'), 'hashed-pw', 'Alice', ['ROLE_USER']);
        $this->issue('raw-token', $owner, 'bob@baander.app');

        $this->expectException(VerificationLinkOtherAccountException::class);

        try {
            ($this->handler())($this->command('raw-token'));
        } finally {
            $this->assertSame(['raw-token'], array_keys($this->tokens), 'The link stays usable for its account.');
            $this->assertFalse($owner->isEmailVerified());
            $this->assertSame([], $this->saves);
            $this->assertSame([], $this->events);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unusableTokens(): iterable
    {
        yield 'empty token' => ['empty'];
        yield 'unknown token' => ['unknown'];
        yield 'expired token' => ['expired'];
        yield 'another account\'s expired token' => ['other-expired'];
        yield 'user gone' => ['no-user'];
        yield 'address changed since' => ['other-address'];
        yield 'disabled account' => ['disabled'];
    }

    #[DataProvider('unusableTokens')]
    public function testAnUnusableTokenGetsOneAnswerAndVerifiesNothing(string $case): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $command = new VerifyEmailCommand($case === 'empty' ? '  ' : 'raw-token', $this->user->getId()->toString());
        $this->issue('raw-token', $this->user, 'alice@baander.app');
        match ($case) {
            'unknown' => $this->tokens = [],
            'expired' => $this->issue('raw-token', $this->user, 'alice@baander.app', live: false),
            'other-expired' => $this->issue('raw-token', User::register(new Email('bob@baander.app'), 'hashed-pw', 'Bob'), 'bob@baander.app', live: false),
            'no-user' => $this->user = null,
            'other-address' => $this->issue('raw-token', $this->user, 'old-alice@baander.app'),
            'disabled' => $this->user->disable(),
            default => null,
        };

        try {
            ($this->handler())($command);
            $this->fail('An unusable token must be rejected.');
        } catch (EmailVerificationException $exception) {
            $this->assertSame('Invalid or expired verification token.', $exception->getMessage());
        }

        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->events);
    }

    /**
     * The pending address replaces the account's address, the session that opened the link stays
     * signed in while every other one ends, and the old address hears of it, both after the commit.
     */
    public function testAPendingAddressTakesEffectEndsTheOtherSessionsAndTellsTheOldAddress(): void
    {
        $this->user = User::createByOperator(new Email('alice@baander.app'), 'hashed-pw', 'Alice', ['ROLE_USER']);
        $this->user->requestEmailChange(new Email('alice.new@baander.app'));
        $this->issue('raw-token', $this->user, 'alice.new@baander.app');
        $this->requestingSession = AccessToken::issue(Client::create('SPA', ['https://baander.app']), SignInGrant::Password, $this->user);

        $emailChanged = ($this->handler())($this->command('raw-token', $this->requestingSession->getTokenId()->toString()));

        $this->assertTrue($emailChanged);
        $this->assertSame('alice.new@baander.app', $this->user->getEmail());
        $this->assertTrue($this->user->isEmailVerified());
        $this->assertNull($this->user->getPendingEmail());
        $this->assertSame([true], $this->saves);
        $this->assertCount(1, $this->events);
        $this->assertSame([$this->requestingSession->getTokenId()->toString()], $this->keptSessions);
        $this->assertSame([[['other-session'], false]], $this->closes, 'The ended sessions\' connections close after the commit.');
        $this->assertSame([['Alice', 'alice@baander.app', false]], $this->notices, 'The old address hears of the change after the commit.');
    }

    public function testAPendingAddressAnotherAccountTookFailsAndKeepsTheAddress(): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $this->user->requestEmailChange(new Email('bob@baander.app'));
        $this->issue('raw-token', $this->user, 'bob@baander.app');
        $this->taken[] = 'bob@baander.app';

        try {
            ($this->handler())($this->command('raw-token'));
            $this->fail('A taken address must not be confirmed.');
        } catch (EmailVerificationException $exception) {
            $this->assertSame('Invalid or expired verification token.', $exception->getMessage());
        }

        $this->assertSame('alice@baander.app', $this->user->getEmail());
        $this->assertNull($this->user->getPendingEmail(), 'The change is dropped.');
        $this->assertSame([true], $this->saves);
        $this->assertSame([], $this->events);
        $this->assertSame([], $this->notices);
        $this->assertSame([], $this->keptSessions, 'A change that did not happen ends no session.');
    }

    public function testATokenForAnAddressNoLongerPendingVerifiesNothing(): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $this->user->requestEmailChange(new Email('alice.second@baander.app'));
        $this->issue('raw-token', $this->user, 'alice.first@baander.app');

        $this->expectException(EmailVerificationException::class);

        try {
            ($this->handler())($this->command('raw-token'));
        } finally {
            $this->assertSame('alice@baander.app', $this->user->getEmail());
            $this->assertSame('alice.second@baander.app', $this->user->getPendingEmail());
            $this->assertSame([], $this->saves);
        }
    }

    public function testAnEventFailureEscapesTheTransaction(): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $this->issue('raw-token', $this->user, 'alice@baander.app');
        $this->eventFailure = new \RuntimeException('Outbox insertion failed.');

        $this->expectExceptionObject($this->eventFailure);

        ($this->handler())($this->command('raw-token'));
    }

    private function issue(string $token, User $owner, string $email, bool $live = true): void
    {
        $this->tokens[$token] = ['owner' => $owner->getId(), 'email' => new Email($email), 'live' => $live];
    }

    /** The command a signed-in session of the test's user sends. */
    private function command(string $token, ?string $accessTokenId = null): VerifyEmailCommand
    {
        $this->assertNotNull($this->user);

        return new VerifyEmailCommand($token, $this->user->getId()->toString(), $accessTokenId);
    }

    private function handler(): VerifyEmailHandler
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?User => $this->user?->getId()->equals($id) === true ? $this->user : null);
        $users->method('save')->willReturnCallback(function (): void {
            $this->saves[] = $this->active;
        });
        $users->method('existsWithEmail')->willReturnCallback(fn (Email $email): bool => in_array($email->toString(), $this->taken, true));
        $users->method('lockCredentialEpoch')->willReturn(0);
        $users->method('advanceCredentialEpoch')->willReturn(1);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->assertTrue($this->active);
            if ($this->eventFailure !== null) {
                throw $this->eventFailure;
            }
            $this->events[] = $event;

            return $event;
        });
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->active = true;
            try {
                return $operation();
            } finally {
                $this->active = false;
            }
        });

        $notice = $this->createStub(EmailChangeNoticeInterface::class);
        $notice->method('deliver')->willReturnCallback(function (User $user, Email $previous): void {
            $this->notices[] = [$user->getName(), $previous->toString(), $this->active];
        });

        return new VerifyEmailHandler($this->tokenStore(), $users, $dispatcher, $transaction, $notice, $this->revoker($users));
    }

    /** Behaves as the stored tokens do: a token is redeemed once, and only by the user it was issued to. */
    private function tokenStore(): EmailVerificationTokenRepositoryInterface
    {
        $tokens = $this->createStub(EmailVerificationTokenRepositoryInterface::class);
        $tokens->method('redeem')->willReturnCallback(function (string $token, Uuid $userId): ?Email {
            $this->assertTrue($this->active, 'Redemption is part of the verification transaction.');
            $issued = $this->tokens[$token] ?? null;
            if ($issued === null || !$issued['owner']->equals($userId)) {
                return null;
            }
            unset($this->tokens[$token]);

            return $issued['live'] ? $issued['email'] : null;
        });
        $tokens->method('isLiveForAnotherUser')->willReturnCallback(function (string $token, Uuid $userId): bool {
            $issued = $this->tokens[$token] ?? null;

            return $issued !== null && $issued['live'] && !$issued['owner']->equals($userId);
        });

        return $tokens;
    }

    /** Ends every session of the user but the one kept, which is "other-session", and records each close. */
    private function revoker(UserRepositoryInterface $users): CredentialChangeRevoker
    {
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('findByTokenId')->willReturnCallback(
            fn (TokenId $id): ?AccessToken => $this->requestingSession?->getTokenId()->equals($id) === true ? $this->requestingSession : null,
        );
        $accessTokens->method('isSessionCurrent')->willReturn(true);
        $accessTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): array {
            $this->assertTrue($this->user?->getId()->equals($userId));
            $this->keptSessions[] = $keep?->getTokenId()->toString() ?? 'none';

            return ['other-session'];
        });
        $connections = $this->createStub(LiveConnectionsPortInterface::class);
        $connections->method('closeForAccessTokens')->willReturnCallback(function (array $accessTokenIds): int {
            $this->closes[] = [$accessTokenIds, $this->active];

            return count($accessTokenIds);
        });

        return new CredentialChangeRevoker(
            $users,
            $accessTokens,
            $this->createStub(RefreshTokenRepositoryInterface::class),
            new ChainConnectionCloser($connections, new NullLogger()),
        );
    }
}
