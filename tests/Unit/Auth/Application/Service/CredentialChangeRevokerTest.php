<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\Service;

use App\Auth\Application\DTO\RevokedSessions;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Service\ChainConnectionCloser;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A credential change locks the account, refuses when its own session was already ended, and
 * otherwise ends every other session and moves the kept one to the new credential epoch.
 */
final class CredentialChangeRevokerTest extends TestCase
{
    /** The user's credential epoch as the lock reads it; the change advances it to 8. */
    private const int LOCKED_EPOCH = 7;

    private User $user;
    /** @var list<string> what reached storage, in order */
    private array $log = [];
    /** Whether storage holds the kept session as signed in under the locked epoch. */
    private bool $keptSessionCurrent = true;
    /** @var list<AccessToken> */
    private array $storedTokens = [];
    /** @var list<list<string>> the access token ids of each close the web server was asked for */
    private array $closed = [];
    /** What the web server answers a close with, when it fails. */
    private ?ServerControlException $closeFailure = null;
    /** @var list<array{level: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    protected function setUp(): void
    {
        $this->user = User::register(new Email('owner@baander.app'), 'hashed', 'Owner');
    }

    /**
     * The change is saved under the lock and before the revocation, which clears the entity
     * manager, so its save compares the aggregate with the state it was loaded with.
     */
    public function testWithoutAKeptSessionTheChangeIsSavedAndEverySessionIsRevoked(): void
    {
        $sessions = $this->revoker()->revokeSessions($this->user, null, $this->change());

        self::assertSame([
            'lock ' . $this->user->getId()->toString(),
            'change',
            'advance ' . $this->user->getId()->toString(),
            'revoke refresh keeping none',
            'revoke access keeping none',
        ], $this->log);
        self::assertTrue($this->user->getId()->equals($sessions->userId));
    }

    public function testTheKeptSessionMovesToTheAdvancedEpochAndTheOthersAreRevoked(): void
    {
        $kept = $this->storedToken($this->user);

        $sessions = $this->revoker()->revokeSessions($this->user, $kept->getTokenId()->toString(), $this->change());

        $keptId = $kept->getTokenId()->toString();
        self::assertSame([
            'lock ' . $this->user->getId()->toString(),
            'is ' . $keptId . ' current at ' . self::LOCKED_EPOCH,
            'change',
            'advance ' . $this->user->getId()->toString(),
            'revoke refresh keeping ' . $keptId,
            'revoke access keeping ' . $keptId,
            'restamp refresh ' . $keptId . ' to 8',
            'restamp access ' . $keptId . ' to 8',
        ], $this->log);
        self::assertTrue($this->user->getId()->equals($sessions->userId));
    }

    /**
     * The kept session was ended while the change waited for the account's row: the change is
     * refused before it writes the credential, advances the epoch or revokes anything, so the
     * caller's transaction rolls back with nothing changed.
     */
    public function testAChangeWhoseSessionWasAlreadyEndedIsRefusedBeforeItChangesAnything(): void
    {
        $kept = $this->storedToken($this->user);
        $this->keptSessionCurrent = false;

        $this->expectException(CredentialsChangedException::class);

        try {
            $sessions = $this->revoker()->revokeSessions($this->user, $kept->getTokenId()->toString(), $this->change());
        } finally {
            self::assertFalse(isset($sessions), 'No sessions were revoked.');
            self::assertSame([
                'lock ' . $this->user->getId()->toString(),
                'is ' . $kept->getTokenId()->toString() . ' current at ' . self::LOCKED_EPOCH,
            ], $this->log);
        }
    }

    public function testAnotherUsersTokenKeepsNoSession(): void
    {
        $other = User::register(new Email('other@baander.app'), 'hashed', 'Other');
        $foreign = $this->storedToken($other);
        $this->keptSessionCurrent = false;

        $sessions = $this->revoker()->revokeSessions($this->user, $foreign->getTokenId()->toString(), $this->change());

        self::assertTrue($this->user->getId()->equals($sessions->userId));
        self::assertContains('revoke access keeping none', $this->log);
        self::assertNotContains('restamp access ' . $foreign->getTokenId()->toString() . ' to 8', $this->log);
    }

    public function testAnUnknownTokenKeepsNoSession(): void
    {
        $sessions = $this->revoker()->revokeSessions($this->user, TokenId::generate()->toString(), $this->change());

        self::assertTrue($this->user->getId()->equals($sessions->userId));
        self::assertContains('revoke access keeping none', $this->log);
    }

    public function testClosingTheConnectionsAsksTheWebServerForEveryRevokedToken(): void
    {
        $this->revoker()->closeConnections(new RevokedSessions($this->user->getId(), ['first-token', 'second-token']));

        self::assertSame([['first-token', 'second-token']], $this->closed);
        self::assertSame([], $this->logRecords);
    }

    public function testAFailedCloseIsLoggedWithTheUserAndTheCountAndNeverThrown(): void
    {
        $this->closeFailure = new ServerControlException('a worker did not answer');

        $this->revoker()->closeConnections(new RevokedSessions($this->user->getId(), ['first-token', 'second-token']));

        self::assertCount(1, $this->logRecords);
        self::assertSame('error', $this->logRecords[0]['level']);
        self::assertSame($this->user->getId()->toString(), $this->logRecords[0]['context']['user']);
        self::assertSame(2, $this->logRecords[0]['context']['connections']);
        self::assertSame(
            ['user', 'connections', 'exception'],
            array_keys($this->logRecords[0]['context']),
            'The log names no token.',
        );
    }

    public function testAConsoleWithoutAWebServerLogsAWarning(): void
    {
        $this->closeFailure = new ServerNotRunningException();

        $this->revoker()->closeConnections(new RevokedSessions($this->user->getId(), ['first-token']));

        self::assertSame(['warning'], array_column($this->logRecords, 'level'));
    }

    /** The caller's credential change, recorded where it runs. */
    private function change(): \Closure
    {
        return function (): void {
            $this->log[] = 'change';
        };
    }

    private function storedToken(User $owner): AccessToken
    {
        $token = AccessToken::issue(Client::create('SPA', ['https://baander.app']), $owner, chainId: ChainId::generate());
        $this->storedTokens[] = $token;

        return $token;
    }

    private function revoker(): CredentialChangeRevoker
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('lockCredentialEpoch')->willReturnCallback(function (Uuid $id): int {
            $this->log[] = 'lock ' . $id->toString();

            return self::LOCKED_EPOCH;
        });
        $users->method('advanceCredentialEpoch')->willReturnCallback(function (Uuid $id): int {
            $this->log[] = 'advance ' . $id->toString();

            return self::LOCKED_EPOCH + 1;
        });

        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('findByTokenId')->willReturnCallback(fn (TokenId $id): ?AccessToken => array_find(
            $this->storedTokens,
            static fn (AccessToken $token): bool => $token->getTokenId()->equals($id),
        ));
        $accessTokens->method('isSessionCurrent')->willReturnCallback(function (AccessToken $token, int $epoch): bool {
            $this->log[] = 'is ' . $token->getTokenId()->toString() . ' current at ' . $epoch;

            return $this->keptSessionCurrent;
        });
        $accessTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): array {
            self::assertTrue($this->user->getId()->equals($userId));
            $this->log[] = 'revoke access keeping ' . ($keep?->getTokenId()->toString() ?? 'none');

            return ['other-session-token'];
        });
        $accessTokens->method('restampCredentialEpoch')->willReturnCallback(function (AccessToken $keep, int $epoch): void {
            $this->log[] = 'restamp access ' . $keep->getTokenId()->toString() . ' to ' . $epoch;
        });

        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): void {
            self::assertTrue($this->user->getId()->equals($userId));
            $this->log[] = 'revoke refresh keeping ' . ($keep?->getTokenId()->toString() ?? 'none');
        });
        $refreshTokens->method('restampCredentialEpoch')->willReturnCallback(function (AccessToken $keep, int $epoch): void {
            $this->log[] = 'restamp refresh ' . $keep->getTokenId()->toString() . ' to ' . $epoch;
        });

        $connections = $this->createStub(LiveConnectionsPortInterface::class);
        $connections->method('closeForAccessTokens')->willReturnCallback(function (array $accessTokenIds): int {
            $this->closed[] = $accessTokenIds;
            if ($this->closeFailure !== null) {
                throw $this->closeFailure;
            }

            return count($accessTokenIds);
        });
        $logger = $this->createStub(LoggerInterface::class);
        foreach (['warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
                $this->logRecords[] = ['level' => $level, 'context' => $context];
            });
        }

        return new CredentialChangeRevoker(
            $users,
            $accessTokens,
            $refreshTokens,
            new ChainConnectionCloser($connections, $logger),
        );
    }
}
