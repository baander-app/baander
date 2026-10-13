<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\CommandHandler\OAuth\RefreshTokenHandler;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Exception\RefreshTokenRejectedException;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\RefreshTokenState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * The refresh grant over stubbed repositories: today's rotation, the lost-response grace
 * decided on rows read under the row lock, and the reuse verdicts that revoke the chain
 * in their own transaction before the rejection.
 */
final class RefreshTokenHandlerTest extends TestCase
{
    private const string JKT = 'bound-proof-key-thumbprint';

    private AccessTokenRepositoryInterface&Stub $accessTokenRepository;
    private RefreshTokenRepositoryInterface&Stub $refreshTokenRepository;
    /** @var list<AccessToken> */
    private array $savedAccessTokens = [];
    /** @var list<RefreshToken> */
    private array $savedRefreshTokens = [];
    private TokenChainValidator $chainValidator;
    private TransactionPortInterface&Stub $transaction;
    private LiveConnectionsPortInterface&Stub $liveConnections;
    /** What the web server throws when asked to close the chain's sockets; null closes them. */
    private ?\Throwable $socketCloseFailure = null;
    /** @var list<list<string>> The access token ids handed to the web server to close, per call. */
    private array $closedAccessTokenIds = [];
    private EntityManagerInterface&Stub $entityManager;
    private JwtGeneratorInterface&Stub $jwtGenerator;
    private TokenMetadataRepositoryInterface&Stub $tokenMetadataRepository;
    /** @var list<TokenMetadata> */
    private array $savedMetadata = [];
    /** What the rotation transaction finds when it locks the client's row. */
    private bool $clientActiveAtIssuance = true;
    /** @var array<string, true> IDs of the users the user repository reports as disabled */
    private array $disabledUserIds = [];
    /** @var array<string, true> IDs of the users the user repository no longer finds */
    private array $deletedUserIds = [];
    /** @var array<string, RefreshToken> The stored rows, by token id; the pre-transaction read and the locked read both return them. */
    private array $rows = [];
    /** @var array<string, list<RefreshToken>> The successors of a row, by its id, as the locked read returns them. */
    private array $successors = [];
    /** What the database answers for the chain of the presented token. */
    private bool $chainHoldsLiveToken = false;
    /** @var list<string> Refresh token row ids revoked by id, in call order. */
    private array $revokedRefreshIds = [];
    /** @var list<string> Access token row ids revoked by id, in call order. */
    private array $revokedAccessIds = [];
    /** @var list<string> Repository and transaction events, in order. */
    private array $events = [];
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];
    private RefreshTokenHandler $handler;

    protected function setUp(): void
    {
        $this->accessTokenRepository = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createStub(RefreshTokenRepositoryInterface::class);
        $this->accessTokenRepository->method('save')->willReturnCallback(function (AccessToken $token): void {
            $this->events[] = 'save access';
            $this->savedAccessTokens[] = $token;
        });
        $this->refreshTokenRepository->method('save')->willReturnCallback(function (RefreshToken $token): void {
            $this->events[] = 'save refresh';
            $this->savedRefreshTokens[] = $token;
        });
        $this->refreshTokenRepository->method('findByTokenId')->willReturnCallback(
            fn (TokenId $tokenId): ?RefreshToken => $this->rows[$tokenId->toString()] ?? null,
        );
        $this->refreshTokenRepository->method('lockByTokenId')->willReturnCallback(function (TokenId $tokenId): ?RefreshToken {
            $this->events[] = 'lock presented';

            return $this->rows[$tokenId->toString()] ?? null;
        });
        $this->refreshTokenRepository->method('lockSuccessors')->willReturnCallback(function (Uuid $id): array {
            $this->events[] = 'lock successors';

            return $this->successors[$id->toString()] ?? [];
        });
        // The window predicate, answered from the row's used_at on this clock.
        $this->refreshTokenRepository->method('consumedWithin')->willReturnCallback(function (Uuid $id, int $seconds): bool {
            $this->events[] = 'window check';
            foreach ($this->rows as $row) {
                if ($row->getId()->equals($id)) {
                    $usedAt = $row->getUsedAt();

                    return $usedAt !== null && $usedAt >= new \DateTimeImmutable(sprintf('-%d seconds', $seconds));
                }
            }

            return false;
        });
        $this->refreshTokenRepository->method('chainHoldsLiveToken')->willReturnCallback(fn (): bool => $this->chainHoldsLiveToken);
        $this->refreshTokenRepository->method('revokeByIds')->willReturnCallback(function (array $ids): int {
            $this->events[] = 'revoke replacements';
            foreach ($ids as $id) {
                $this->revokedRefreshIds[] = $id->toString();
            }

            return count($ids);
        });
        $this->accessTokenRepository->method('revokeByIds')->willReturnCallback(function (array $ids): int {
            $this->events[] = 'revoke replacement access tokens';
            foreach ($ids as $id) {
                $this->revokedAccessIds[] = $id->toString();
            }

            return count($ids);
        });
        $this->refreshTokenRepository->method('revokeByChainId')->willReturnCallback(function (ChainId $chainId): void {
            $this->events[] = 'revoke chain refresh tokens ' . $chainId->toString();
        });
        $this->accessTokenRepository->method('findTokenIdsByChainId')->willReturnCallback(function (ChainId $chainId): array {
            $this->events[] = 'list chain access tokens ' . $chainId->toString();
            $ids = [];
            foreach ($this->rows as $row) {
                if ($row->getChainId()?->equals($chainId) ?? false) {
                    $ids[] = $row->getAccessToken()->getTokenId()->toString();
                }
            }

            return $ids;
        });
        $this->accessTokenRepository->method('revokeByChainId')->willReturnCallback(function (ChainId $chainId): void {
            $this->events[] = 'revoke chain access tokens ' . $chainId->toString();
        });
        $this->liveConnections = $this->createStub(LiveConnectionsPortInterface::class);
        $this->liveConnections->method('closeForAccessTokens')->willReturnCallback(function (array $ids): int {
            $this->events[] = 'close sockets';
            $this->closedAccessTokenIds[] = $ids;
            if ($this->socketCloseFailure !== null) {
                throw $this->socketCloseFailure;
            }

            return count($ids);
        });

        // TokenChainValidator is final and cannot be mocked. Use a real instance
        // with dedicated repository mocks for its dependency chain.
        $this->chainValidator = new TokenChainValidator();

        $this->transaction = $this->createStub(TransactionPortInterface::class);
        $this->transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->events[] = 'begin';
            try {
                $result = $operation();
            } catch (\Throwable $exception) {
                $this->events[] = 'rolled back';
                throw $exception;
            }
            $this->events[] = 'committed';

            return $result;
        });
        $this->entityManager = $this->createStub(EntityManagerInterface::class);

        $this->jwtGenerator = $this->createStub(JwtGeneratorInterface::class);
        $this->jwtGenerator->method('generate')->willReturn('mock-jwt-token');

        $this->refreshTokenRepository
            ->method('consumeByTokenId')
            ->willReturnCallback(function (TokenId $tokenId): ?RefreshToken {
                $token = $this->rows[$tokenId->toString()] ?? null;

                if ($token === null || $token->hasBeenUsed() || $token->isRevoked() || $token->isExpired()) {
                    return null;
                }

                $token->markUsed();

                return $token;
            });

        $this->tokenMetadataRepository = $this->createStub(TokenMetadataRepositoryInterface::class);
        $this->tokenMetadataRepository->method('save')->willReturnCallback(function (TokenMetadata $metadata): void {
            $this->savedMetadata[] = $metadata;
        });

        $this->handler = $this->buildHandler(60);
    }

    // --- Happy path ---

    public function testValidRefreshTokenReturnsNewTokenPair(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile'), new Scope('library')],
            'My Token',
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = $this->store(RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        ));

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        );

        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
        $this->assertNotEmpty($result->getRefreshToken());
        $this->assertEquals(3600, $result->getExpiresIn());
        $this->assertContains('profile', $result->getScopes());
        $this->assertContains('library', $result->getScopes());
        self::assertCount(1, $this->savedRefreshTokens, 'The consumed token is stamped by the conditional UPDATE alone, never re-saved.');
        self::assertCount(2, $this->savedAccessTokens);
        self::assertSame($oldAccessToken, $this->savedAccessTokens[0]);
        self::assertNotSame($oldRefreshToken, $this->savedRefreshTokens[0]);
        self::assertNotSame($oldAccessToken, $this->savedAccessTokens[1]);
        self::assertNotContains('lock presented', $this->events, 'An unused token takes the rotation path without the locked evaluation.');
    }

    public function testReplacementKeepsTheClientFingerprintBinding(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $chainId = ChainId::generate();
        $oldAccessToken = AccessToken::issue($this->createConfidentialClient(), $user, [new Scope('profile')], null, new \DateInterval('PT3600S'), $chainId, dpopJkt: self::JKT);
        $oldRefreshToken = $this->store(RefreshToken::issue($oldAccessToken, $chainId, new \DateInterval('PT2592000S')));
        $this->tokenMetadataRepository->method('findByTokenId')->willReturnCallback(
            static fn (TokenId $tokenId): ?TokenMetadata => $tokenId->equals($oldAccessToken->getTokenId())
                ? TokenMetadata::create($oldAccessToken->getId(), clientFingerprint: 'bound-fingerprint')
                : null,
        );

        ($this->handler)(new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            ipAddress: '127.0.0.2',
            userAgent: 'PHPUnit/13',
            dpopJkt: self::JKT,
        ));

        self::assertCount(1, $this->savedMetadata);
        self::assertTrue($this->savedMetadata[0]->getTokenId()->equals($this->savedAccessTokens[1]->getId()));
        self::assertSame('bound-fingerprint', $this->savedMetadata[0]->getClientFingerprint());
        self::assertSame('127.0.0.2', $this->savedMetadata[0]->getIpAddress());
        self::assertSame('PHPUnit/13', $this->savedMetadata[0]->getUserAgent());
    }

    public function testOldAccessTokenIsRevoked(): void
    {
        $oldRefreshToken = $this->boundRefreshToken();

        ($this->handler)(new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        ));

        // After handler runs, the old access token should be revoked
        $this->assertTrue($oldRefreshToken->getAccessToken()->isRevoked());
    }

    public function testOldRefreshTokenIsMarkedUsed(): void
    {
        $oldRefreshToken = $this->boundRefreshToken();

        ($this->handler)(new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        ));

        $this->assertTrue($oldRefreshToken->hasBeenUsed());
    }

    // --- Lost-response grace ---

    /** AE1: the retry of a lost response gets a fresh pair; the unused replacement is superseded. */
    public function testAUsedTokenPresentedAgainWithinTheWindowGetsAFreshPairAndSupersedesTheUnusedReplacement(): void
    {
        $chainId = ChainId::generate();
        $usedAt = new \DateTimeImmutable('-5 seconds');
        $r1 = $this->usedRefreshToken($chainId, $usedAt);
        $r2 = $this->replacementOf($r1);
        $this->tokenMetadataRepository->method('findByTokenId')->willReturnCallback(
            static fn (TokenId $tokenId): ?TokenMetadata => $tokenId->equals($r1->getAccessToken()->getTokenId())
                ? TokenMetadata::create($r1->getAccessToken()->getId(), clientFingerprint: 'bound-fingerprint')
                : null,
        );

        $result = ($this->handler)(new RefreshTokenCommand(
            refreshTokenId: $r1->getTokenId()->toString(),
            ipAddress: '10.0.0.7',
            dpopJkt: self::JKT,
        ));

        self::assertNotSame($r1->getTokenId()->toString(), $result->getRefreshToken());
        self::assertNotSame($r2->getTokenId()->toString(), $result->getRefreshToken());
        self::assertSame([$r2->getId()->toString()], $this->revokedRefreshIds, 'The live unused replacement is revoked by id.');
        self::assertSame([$r2->getAccessToken()->getId()->toString()], $this->revokedAccessIds, 'And so is its access token.');
        self::assertCount(1, $this->savedRefreshTokens, 'Only the fresh refresh token is written; the presented one is left as it is.');
        $r3 = $this->savedRefreshTokens[0];
        self::assertSame($r1, $r3->getPreviousRefreshToken(), 'The fresh pair descends from the presented token.');
        self::assertSame($result->getRefreshToken(), $r3->getTokenId()->toString());
        self::assertTrue($r3->getChainId()?->equals($chainId) ?? false);
        self::assertCount(1, $this->savedAccessTokens);
        self::assertSame(self::JKT, $this->savedAccessTokens[0]->getDpopJkt());
        self::assertSame($usedAt, $r1->getUsedAt(), 'The presented token keeps its first consumption time.');
        self::assertSame('bound-fingerprint', $this->savedMetadata[0]->getClientFingerprint());
        self::assertSame('10.0.0.7', $this->savedMetadata[0]->getIpAddress());
        self::assertSame(
            ['begin', 'lock presented', 'lock successors', 'window check', 'revoke replacements', 'revoke replacement access tokens', 'save access', 'save refresh', 'committed'],
            $this->events,
            'Revocations by id run before any persist, inside the issuing transaction.',
        );
        $this->assertLogged('info', 'grace', $chainId, [$r1, $r2, $r3]);
    }

    /** A second lost response within the window supersedes the pair the first grace issued. */
    public function testAGraceRepeatedWithinTheWindowSupersedesOnlyTheLiveReplacement(): void
    {
        $chainId = ChainId::generate();
        $r1 = $this->usedRefreshToken($chainId, new \DateTimeImmutable('-20 seconds'));
        $superseded = $this->replacementOf($r1);
        $superseded->revoke();
        $superseded->getAccessToken()->revoke();
        $live = $this->replacementOf($r1);

        $result = ($this->handler)(new RefreshTokenCommand(refreshTokenId: $r1->getTokenId()->toString(), dpopJkt: self::JKT));

        self::assertNotEmpty($result->getRefreshToken());
        self::assertSame([$live->getId()->toString()], $this->revokedRefreshIds);
        self::assertSame([$live->getAccessToken()->getId()->toString()], $this->revokedAccessIds);
        self::assertSame($r1, $this->savedRefreshTokens[0]->getPreviousRefreshToken());
    }

    /** AE2: a replacement that was already used means the presented token is a replay. */
    public function testAUsedReplacementRevokesTheChainInItsOwnTransactionBeforeTheRejection(): void
    {
        $chainId = ChainId::generate();
        $r1 = $this->usedRefreshToken($chainId, new \DateTimeImmutable('-5 seconds'));
        $r2 = $this->replacementOf($r1);
        $r2->markUsed();

        $this->assertRejected(
            $r1,
            'Refresh token reuse detected: a replacement was already used.',
            ['begin', 'lock presented', 'lock successors', 'window check', 'rolled back', 'begin', 'revoke chain refresh tokens ' . $chainId->toString(), 'list chain access tokens ' . $chainId->toString(), 'revoke chain access tokens ' . $chainId->toString(), 'committed', 'close sockets'],
        );
        self::assertSame([], $this->savedRefreshTokens);
        $this->assertLogged('warning', 'replacement used', $chainId, [$r1, $r2]);
    }

    /** A reuse verdict closes the sockets opened with the chain's access tokens, as a logout does. */
    public function testAReuseVerdictClosesTheSocketsOfTheWholeChainAfterTheCommit(): void
    {
        $chainId = ChainId::generate();
        $r1 = $this->usedRefreshToken($chainId, new \DateTimeImmutable('-5 seconds'));
        $r2 = $this->replacementOf($r1);
        $r2->markUsed();

        $this->assertRejected(
            $r1,
            'Refresh token reuse detected: a replacement was already used.',
            ['begin', 'lock presented', 'lock successors', 'window check', 'rolled back', 'begin', 'revoke chain refresh tokens ' . $chainId->toString(), 'list chain access tokens ' . $chainId->toString(), 'revoke chain access tokens ' . $chainId->toString(), 'committed', 'close sockets'],
        );
        self::assertSame(
            [[$r1->getAccessToken()->getTokenId()->toString(), $r2->getAccessToken()->getTokenId()->toString()]],
            $this->closedAccessTokenIds,
            'Every access token of the chain, listed under the refresh-row locks, is handed to the web server.',
        );
    }

    /** The verdict stands when the web server cannot close the sockets: the chain is revoked and the 401 is answered. */
    public function testAReuseVerdictIsAnsweredEvenWhenTheSocketsCannotBeClosed(): void
    {
        $chainId = ChainId::generate();
        $r1 = $this->usedRefreshToken($chainId, new \DateTimeImmutable('-5 seconds'));
        $r2 = $this->replacementOf($r1);
        $r2->markUsed();
        $this->socketCloseFailure = new ServerControlException('a worker did not answer');

        $this->assertRejected(
            $r1,
            'Refresh token reuse detected: a replacement was already used.',
            ['begin', 'lock presented', 'lock successors', 'window check', 'rolled back', 'begin', 'revoke chain refresh tokens ' . $chainId->toString(), 'list chain access tokens ' . $chainId->toString(), 'revoke chain access tokens ' . $chainId->toString(), 'committed', 'close sockets'],
        );
        $errors = array_values(array_filter($this->logs, static fn (array $record): bool => $record['level'] === 'error'));
        self::assertCount(1, $errors);
        self::assertSame($chainId->toString(), $errors[0]['context']['chain'] ?? null);
        $this->assertLogged('warning', 'replacement used', $chainId, [$r1, $r2]);
    }

    public function testAUsedTokenPresentedOutsideTheWindowRevokesTheChain(): void
    {
        $chainId = ChainId::generate();
        $r1 = $this->usedRefreshToken($chainId, new \DateTimeImmutable('-5 minutes'));
        $r2 = $this->replacementOf($r1);

        $this->assertRejected(
            $r1,
            'Refresh token reuse detected: presented again outside the grace window.',
            ['begin', 'lock presented', 'lock successors', 'window check', 'rolled back', 'begin', 'revoke chain refresh tokens ' . $chainId->toString(), 'list chain access tokens ' . $chainId->toString(), 'revoke chain access tokens ' . $chainId->toString(), 'committed', 'close sockets'],
        );
        self::assertSame([], $this->revokedRefreshIds, 'No replacement is superseded on a reuse verdict.');
        $this->assertLogged('warning', 'outside window', $chainId, [$r1, $r2]);
    }

    public function testAWindowOfZeroDisablesTheGrace(): void
    {
        $this->handler = $this->buildHandler(0);
        $chainId = ChainId::generate();
        $r1 = $this->usedRefreshToken($chainId, new \DateTimeImmutable('-1 second'));
        $this->replacementOf($r1);

        $this->assertRejected(
            $r1,
            'Refresh token reuse detected: presented again outside the grace window.',
            ['begin', 'lock presented', 'lock successors', 'rolled back', 'begin', 'revoke chain refresh tokens ' . $chainId->toString(), 'list chain access tokens ' . $chainId->toString(), 'revoke chain access tokens ' . $chainId->toString(), 'committed', 'close sockets'],
        );
    }

    /** @return iterable<string, array{int}> */
    public static function windowsOutOfRange(): iterable
    {
        yield 'negative' => [-1];
        yield 'over a minute' => [61];
    }

    #[DataProvider('windowsOutOfRange')]
    public function testTheGraceWindowIsValidatedToAtMostAMinute(int $seconds): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('auth.refresh.reuse_grace_seconds');

        $this->buildHandler($seconds);
    }

    /** A different key never revokes: the holder of the key, not the thief, keeps the chain. */
    public function testAUsedTokenPresentedWithAnotherKeyIsRefusedWithoutLockingOrRevoking(): void
    {
        $chainId = ChainId::generate();
        $r1 = $this->usedRefreshToken($chainId, new \DateTimeImmutable('-5 seconds'));
        $this->replacementOf($r1);

        try {
            ($this->handler)(new RefreshTokenCommand(refreshTokenId: $r1->getTokenId()->toString(), dpopJkt: 'other-proof-key-thumbprint'));
            self::fail('A proof from another key must be rejected.');
        } catch (RefreshTokenRejectedException $exception) {
            self::assertSame('Refresh token proof binding does not match.', $exception->getMessage());
            self::assertNull($exception->chainToRevoke);
        }
        self::assertSame([], $this->events, 'Nothing is locked, revoked or written.');
        $this->assertLogged('warning', 'different key', $chainId, [$r1]);
    }

    public function testASupersededTokenPresentedWithItsOwnKeyRevokesTheChainThatStillHoldsALiveToken(): void
    {
        $chainId = ChainId::generate();
        $r1 = $this->usedRefreshToken($chainId, new \DateTimeImmutable('-10 seconds'));
        $r2 = $this->replacementOf($r1);
        $r2->revoke();
        $this->chainHoldsLiveToken = true;

        $this->assertRejected(
            $r2,
            'Refresh token reuse detected: a superseded token was presented.',
            ['begin', 'lock presented', 'lock successors', 'rolled back', 'begin', 'revoke chain refresh tokens ' . $chainId->toString(), 'list chain access tokens ' . $chainId->toString(), 'revoke chain access tokens ' . $chainId->toString(), 'committed', 'close sockets'],
        );
        $this->assertLogged('warning', 'superseded token presented', $chainId, [$r1, $r2]);
    }

    public function testASupersededTokenPresentedWithAnotherKeyRevokesNothing(): void
    {
        $r1 = $this->usedRefreshToken(ChainId::generate(), new \DateTimeImmutable('-10 seconds'));
        $r2 = $this->replacementOf($r1);
        $r2->revoke();
        $this->chainHoldsLiveToken = true;

        $this->expectException(RefreshTokenRejectedException::class);
        $this->expectExceptionMessage('proof binding does not match');

        try {
            ($this->handler)(new RefreshTokenCommand(refreshTokenId: $r2->getTokenId()->toString(), dpopJkt: 'other-proof-key-thumbprint'));
        } finally {
            self::assertSame([], $this->events);
        }
    }

    /** A revoked unused token of a chain with no live token is simply revoked, as after a logout. */
    public function testARevokedUnusedTokenOfADeadChainIsRefusedAsRevoked(): void
    {
        $r1 = $this->usedRefreshToken(ChainId::generate(), new \DateTimeImmutable('-10 seconds'));
        $r1->revoke();
        $r2 = $this->replacementOf($r1);
        $r2->revoke();
        $this->chainHoldsLiveToken = false;

        $this->assertRejected($r2, 'Refresh token has been revoked.', ['begin', 'lock presented', 'lock successors', 'rolled back']);
    }

    public function testARevokedUsedTokenIsRefusedAsRevokedWithoutLocking(): void
    {
        $r1 = $this->usedRefreshToken(ChainId::generate(), new \DateTimeImmutable('-10 seconds'));
        $r1->revoke();

        $this->assertRejected($r1, 'Refresh token has been revoked.', []);
    }

    /** Every replacement already revoked by a competing grace: refused, and the chain stays as it is. */
    public function testACompetingGraceLoserIsRefusedWithTheChainIntact(): void
    {
        $chainId = ChainId::generate();
        $r1 = $this->usedRefreshToken($chainId, new \DateTimeImmutable('-5 seconds'));
        $r2 = $this->replacementOf($r1);
        $r2->revoke();

        $this->assertRejected(
            $r1,
            'Refresh token was consumed by a concurrent request.',
            ['begin', 'lock presented', 'lock successors', 'window check', 'rolled back'],
        );
        self::assertSame([], $this->revokedRefreshIds);
        $this->assertLogged('info', 'concurrent loser', $chainId, [$r1, $r2]);
    }

    // --- Error cases ---

    public function testNotFoundRefreshTokenThrows(): void
    {
        $command = new RefreshTokenCommand(
            refreshTokenId: str_repeat('a', 80),
            dpopJkt: self::JKT,
        );

        $this->expectException(RefreshTokenRejectedException::class);
        $this->expectExceptionMessage('Refresh token not found');

        ($this->handler)($command);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedRefreshTokens(): iterable
    {
        yield 'too short' => ['short-token'];
        yield 'whitespace' => ['   '];
    }

    #[DataProvider('malformedRefreshTokens')]
    public function testAMalformedRefreshTokenIsRejected(string $refreshToken): void
    {
        $this->expectException(RefreshTokenRejectedException::class);
        $this->expectExceptionMessage('Refresh token not found');

        ($this->handler)(new RefreshTokenCommand(refreshTokenId: $refreshToken, dpopJkt: self::JKT));
    }

    public function testAnInfrastructureFailureIsNotAGrantRejection(): void
    {
        $failure = new ConnectionException(
            new class ('connection to server was lost', '08006') extends AbstractException {
            },
            null,
        );
        $refreshTokenRepository = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokenRepository->method('findByTokenId')->willThrowException($failure);
        $this->refreshTokenRepository = $refreshTokenRepository;
        $handler = $this->buildHandler(60);

        try {
            $handler(new RefreshTokenCommand(refreshTokenId: str_repeat('a', 80), dpopJkt: self::JKT));
            self::fail('The lookup failure must propagate.');
        } catch (\Throwable $exception) {
            self::assertSame($failure, $exception);
        }
    }

    /** @return iterable<string, array{string|null, string|null}> */
    public static function unmatchedProofBindings(): iterable
    {
        yield 'proof from another key' => [self::JKT, 'other-proof-key-thumbprint'];
        yield 'missing proof key' => [self::JKT, null];
        yield 'token issued without a binding' => [null, self::JKT];
    }

    #[DataProvider('unmatchedProofBindings')]
    public function testRefreshRequiresTheProofKeyTheTokenWasIssuedTo(?string $boundJkt, ?string $proofJkt): void
    {
        $chainId = ChainId::generate();
        $accessToken = AccessToken::issue(
            $this->createConfidentialClient(),
            User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User'),
            [new Scope('profile')],
            null,
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: $boundJkt,
        );
        $refreshToken = $this->store(RefreshToken::issue($accessToken, $chainId, new \DateInterval('PT2592000S')));

        try {
            ($this->handler)(new RefreshTokenCommand(
                refreshTokenId: $refreshToken->getTokenId()->toString(),
                dpopJkt: $proofJkt,
            ));
            self::fail('A refresh with an unmatched proof key must be rejected.');
        } catch (RefreshTokenRejectedException $exception) {
            self::assertSame('Refresh token proof binding does not match.', $exception->getMessage());
        }
        self::assertFalse($refreshToken->hasBeenUsed());
        self::assertSame([], $this->savedAccessTokens);
        self::assertSame([], $this->savedRefreshTokens);
    }

    public function testRotatedAccessTokenKeepsTheProofKeyBinding(): void
    {
        $refreshToken = $this->boundRefreshToken();

        ($this->handler)(new RefreshTokenCommand(
            refreshTokenId: $refreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        ));

        self::assertSame(self::JKT, $this->savedAccessTokens[1]->getDpopJkt());
    }

    public function testATokenOfARevokedClientCannotBeRefreshed(): void
    {
        $refreshToken = $this->boundRefreshToken();
        $refreshToken->getAccessToken()->getClient()->revoke();

        try {
            ($this->handler)(new RefreshTokenCommand(refreshTokenId: $refreshToken->getTokenId()->toString(), dpopJkt: self::JKT));
            self::fail('A revoked client must not refresh.');
        } catch (OAuthProtocolException $exception) {
            self::assertSame('invalid_client', $exception->error);
        }
        self::assertFalse($refreshToken->hasBeenUsed());
    }

    public function testATokenOfADisabledAccountCannotBeRefreshed(): void
    {
        $refreshToken = $this->boundRefreshToken();
        // The stored account is disabled; the user loaded with the token does not say so.
        $this->disabledUserIds[$refreshToken->getAccessToken()->getUser()?->getId()->toString() ?? ''] = true;

        $rejection = null;
        try {
            ($this->handler)(new RefreshTokenCommand(refreshTokenId: $refreshToken->getTokenId()->toString(), dpopJkt: self::JKT));
        } catch (RefreshTokenRejectedException $exception) {
            $rejection = $exception;
        }

        // A grant rejection, which the token endpoint reports as invalid_grant.
        self::assertNotNull($rejection, 'A disabled account must not refresh.');
        self::assertInstanceOf(RefreshTokenRejectedException::class, $rejection);
        self::assertFalse($refreshToken->hasBeenUsed());
        self::assertSame([], $this->savedAccessTokens);
    }

    public function testATokenOfADeletedAccountCannotBeRefreshed(): void
    {
        $refreshToken = $this->boundRefreshToken();
        // The account was deleted after the token was loaded with its user.
        $this->deletedUserIds[$refreshToken->getAccessToken()->getUser()?->getId()->toString() ?? ''] = true;

        $rejection = null;
        try {
            ($this->handler)(new RefreshTokenCommand(refreshTokenId: $refreshToken->getTokenId()->toString(), dpopJkt: self::JKT));
        } catch (RefreshTokenRejectedException $exception) {
            $rejection = $exception;
        }

        // A grant rejection, which the token endpoint reports as invalid_grant.
        self::assertNotNull($rejection, 'A deleted account must not refresh.');
        self::assertInstanceOf(RefreshTokenRejectedException::class, $rejection);
        self::assertSame('Refresh token belongs to a disabled or deleted account.', $rejection->getMessage());
        self::assertFalse($refreshToken->hasBeenUsed());
        self::assertSame([], $this->savedAccessTokens);
    }

    public function testAClientRevokedAfterLoadingFailsUnderTheRowLockWithoutRotating(): void
    {
        $refreshToken = $this->boundRefreshToken();
        $this->clientActiveAtIssuance = false;

        try {
            ($this->handler)(new RefreshTokenCommand(refreshTokenId: $refreshToken->getTokenId()->toString(), dpopJkt: self::JKT));
            self::fail('The locked client row decides, not the state loaded before the transaction.');
        } catch (OAuthProtocolException $exception) {
            self::assertSame('invalid_client', $exception->error);
            self::assertSame(401, $exception->statusCode);
        }
        self::assertFalse($refreshToken->hasBeenUsed());
        self::assertSame([], $this->savedAccessTokens);
        self::assertSame([], $this->savedRefreshTokens);
    }

    public function testAnotherClientsRevokedTokenIsReportedAsForeignNotAsInvalidClient(): void
    {
        $refreshToken = $this->boundRefreshToken();
        $refreshToken->getAccessToken()->getClient()->revoke();

        $this->expectException(RefreshTokenRejectedException::class);
        $this->expectExceptionMessage('not issued to this client');

        ($this->handler)(new RefreshTokenCommand(
            refreshTokenId: $refreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
            clientId: Uuid::generate(),
        ));
    }

    public function testAtTheTokenEndpointTheTokenMustBelongToTheAuthenticatedClient(): void
    {
        $refreshToken = $this->boundRefreshToken();

        $this->expectException(RefreshTokenRejectedException::class);
        $this->expectExceptionMessage('not issued to this client');

        try {
            ($this->handler)(new RefreshTokenCommand(
                refreshTokenId: $refreshToken->getTokenId()->toString(),
                dpopJkt: self::JKT,
                clientId: Uuid::generate(),
            ));
        } finally {
            self::assertFalse($refreshToken->hasBeenUsed());
        }
    }

    public function testAtTheTokenEndpointTheIssuingClientMayRefresh(): void
    {
        $refreshToken = $this->boundRefreshToken();

        $result = ($this->handler)(new RefreshTokenCommand(
            refreshTokenId: $refreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
            clientId: $refreshToken->getAccessToken()->getClient()->getId(),
        ));

        self::assertNotEmpty($result->getRefreshToken());
        self::assertTrue($refreshToken->hasBeenUsed());
    }

    public function testExpiredRefreshTokenThrows(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        // Create an access token with no expiry
        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null, // no TTL
            $chainId,
            dpopJkt: self::JKT,
        );

        // Reconstitute a refresh token with an expired date
        $oldRefreshToken = $this->store(RefreshToken::reconstitute(new RefreshTokenState(
            id: Uuid::generate(),
            tokenId: TokenId::generate(),
            accessToken: $oldAccessToken,
            chainId: $chainId,
            previousRefreshToken: null,
            expiresAt: new \DateTimeImmutable('-1 hour'), // expired
            usedAt: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            revoked: false,
        )));

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        );

        $this->expectException(RefreshTokenRejectedException::class);
        $this->expectExceptionMessage('Refresh token has expired');

        ($this->handler)($command);
    }

    public function testTokenWithoutChainIdSkipsValidation(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();

        // Create token pair without chainId (outside rotation model)
        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            null, // no chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = $this->store(RefreshToken::issue(
            $oldAccessToken,
            null, // no chainId
            null,
        ));

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        );

        // Should succeed without any chain validation
        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
    }

    /** A chainless token has no chain to grace into; used again, it is simply refused. */
    public function testAUsedTokenWithoutAChainIsRefusedWithoutRevoking(): void
    {
        $accessToken = AccessToken::issue($this->createConfidentialClient(), null, [new Scope('profile')], null, null, null, dpopJkt: self::JKT);
        $refreshToken = $this->store(RefreshToken::issue($accessToken, null, null));
        $refreshToken->markUsed();

        $this->assertRejected($refreshToken, 'Refresh token was consumed by a concurrent request.', ['begin', 'lock presented', 'rolled back']);
    }

    // --- Metadata storage ---

    public function testMetadataIsStoredWhenFingerprintProvided(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = $this->store(RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            null,
        ));

        // Mock repository to return null (entity not found) -- handler silently skips
        $tokenRepo = $this->createStub(\Doctrine\ORM\EntityRepository::class);
        $tokenRepo->method('findOneBy')->willReturn(null);
        $this->entityManager->method('getRepository')->willReturn($tokenRepo);

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            ipAddress: '10.0.0.1',
            userAgent: 'Test Browser',
            clientFingerprint: 'fingerprint-123',
            dpopJkt: self::JKT,
        );

        // Should not throw -- just skips metadata storage when entity not found
        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
    }

    public function testMetadataIsSkippedWhenNoFingerprint(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = $this->store(RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            null,
        ));

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            clientFingerprint: null,
            dpopJkt: self::JKT,
        );

        $result = ($this->handler)($command);

        $this->assertNotEmpty($result->getAccessToken());
    }

    // --- New token preserves scopes and name ---

    public function testNewTokenPairPreservesScopesAndName(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('email'), new Scope('library')],
            'My Laptop',
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = $this->store(RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            new \DateInterval('PT2592000S'),
        ));

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        );

        $result = ($this->handler)($command);

        $this->assertCount(2, $result->getScopes());
        $this->assertContains('email', $result->getScopes());
        $this->assertContains('library', $result->getScopes());
    }

    // --- Transaction rollback test ---

    public function testTransactionRollbackOnFailure(): void
    {
        $user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $client = $this->createConfidentialClient();
        $chainId = ChainId::generate();

        $oldAccessToken = AccessToken::issue(
            $client,
            $user,
            [new Scope('profile')],
            null,
            null,
            $chainId,
            dpopJkt: self::JKT,
        );

        $oldRefreshToken = RefreshToken::issue(
            $oldAccessToken,
            $chainId,
            null,
        );

        // Use completely fresh mocks to avoid setUp interference
        $accessTokenRepo = $this->createMock(AccessTokenRepositoryInterface::class);
        $refreshTokenRepo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $refreshTokenRepo->method('findByTokenId')->willReturn($oldRefreshToken);
        $refreshTokenRepo->method('consumeByTokenId')->willReturnCallback(function (TokenId $tokenId) use ($oldRefreshToken): ?RefreshToken {
            $token = $oldRefreshToken;
            if ($token->hasBeenUsed() || $token->isRevoked() || $token->isExpired()) {
                return null;
            }
            $token->markUsed();
            return $token;
        });
        $refreshTokenRepo->expects($this->never())->method('save');
        $accessTokenRepo->expects($this->never())->method('save');

        // Make the transaction throw to simulate a DB failure
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willThrowException(
            new RuntimeException('Database error'),
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $jwtGenerator = $this->createStub(JwtGeneratorInterface::class);
        $jwtGenerator->method('generate')->willReturn('mock-jwt-token');

        $handler = new RefreshTokenHandler(
            $accessTokenRepo,
            $refreshTokenRepo,
            $this->chainValidator,
            $entityManager,
            $jwtGenerator,
            $this->createStub(TokenMetadataRepositoryInterface::class),
            $this->activeClients(),
            $this->users(),
            $transaction,
            $this->logger(),
            $this->liveConnections,
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
            reuseGraceSeconds: 60,
        );

        $command = new RefreshTokenCommand(
            refreshTokenId: $oldRefreshToken->getTokenId()->toString(),
            dpopJkt: self::JKT,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database error');

        ($handler)($command);
    }

    // --- Helpers ---

    private function buildHandler(int $reuseGraceSeconds): RefreshTokenHandler
    {
        return new RefreshTokenHandler(
            $this->accessTokenRepository,
            $this->refreshTokenRepository,
            $this->chainValidator,
            $this->entityManager,
            $this->jwtGenerator,
            $this->tokenMetadataRepository,
            $this->activeClients(),
            $this->users(),
            $this->transaction,
            $this->logger(),
            $this->liveConnections,
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
            reuseGraceSeconds: $reuseGraceSeconds,
        );
    }

    private function logger(): AbstractLogger
    {
        $record = function (string $level, string $message, array $context): void {
            $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
        };

        return new class ($record) extends AbstractLogger {
            public function __construct(private readonly \Closure $record)
            {
            }

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->record)((string) $level, (string) $message, $context);
            }
        };
    }

    /**
     * One record names the reason with the chain id, and no record carries a token string.
     *
     * @param list<RefreshToken> $tokens
     */
    private function assertLogged(string $level, string $reason, ChainId $chainId, array $tokens): void
    {
        $matching = array_values(array_filter(
            $this->logs,
            static fn (array $record): bool => ($record['context']['reason'] ?? null) === $reason,
        ));
        self::assertCount(1, $matching, sprintf('Expected one "%s" record, got: %s', $reason, json_encode($this->logs, JSON_THROW_ON_ERROR)));
        self::assertSame($level, $matching[0]['level']);
        self::assertSame($chainId->toString(), $matching[0]['context']['chain_id'] ?? null);
        $serialized = json_encode($this->logs, JSON_THROW_ON_ERROR);
        foreach ($tokens as $token) {
            self::assertStringNotContainsString($token->getTokenId()->toString(), $serialized, 'A log never carries a token string.');
            self::assertStringNotContainsString($token->getAccessToken()->getTokenId()->toString(), $serialized, 'A log never carries a token string.');
        }
    }

    /** @param list<string> $events */
    private function assertRejected(RefreshToken $presented, string $message, array $events): void
    {
        try {
            ($this->handler)(new RefreshTokenCommand(refreshTokenId: $presented->getTokenId()->toString(), dpopJkt: self::JKT));
            self::fail('The presentation must be rejected.');
        } catch (RefreshTokenRejectedException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
        self::assertSame($events, $this->events);
        self::assertSame([], $this->savedAccessTokens);
        self::assertSame([], $this->savedRefreshTokens);
    }

    private function store(RefreshToken $token): RefreshToken
    {
        $this->rows[$token->getTokenId()->toString()] = $token;

        return $token;
    }

    /** A stored token that a rotation consumed at the given time; its access token is revoked, as the rotation left it. */
    private function usedRefreshToken(ChainId $chainId, \DateTimeImmutable $usedAt): RefreshToken
    {
        $accessToken = AccessToken::issue(
            $this->createConfidentialClient(),
            User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User'),
            [new Scope('profile'), new Scope('library')],
            'Browser',
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: self::JKT,
        );
        $accessToken->revoke();

        return $this->store(RefreshToken::reconstitute(new RefreshTokenState(
            id: Uuid::generate(),
            tokenId: TokenId::generate(),
            accessToken: $accessToken,
            chainId: $chainId,
            previousRefreshToken: null,
            expiresAt: new \DateTimeImmutable('+30 days'),
            usedAt: $usedAt,
            createdAt: new \DateTimeImmutable('-1 hour'),
            updatedAt: $usedAt,
            revoked: false,
        )));
    }

    /** A stored, unused replacement issued with the given token as its predecessor. */
    private function replacementOf(RefreshToken $predecessor): RefreshToken
    {
        $template = $predecessor->getAccessToken();
        $accessToken = AccessToken::issue(
            $template->getClient(),
            $template->getUser(),
            $template->getScopes(),
            $template->getName(),
            new \DateInterval('PT3600S'),
            $predecessor->getChainId(),
            dpopJkt: self::JKT,
        );
        $replacement = $this->store(RefreshToken::issue($accessToken, $predecessor->getChainId(), new \DateInterval('PT2592000S'), $predecessor));
        $this->successors[$predecessor->getId()->toString()][] = $replacement;

        return $replacement;
    }

    private function boundRefreshToken(): RefreshToken
    {
        $chainId = ChainId::generate();
        $accessToken = AccessToken::issue(
            $this->createConfidentialClient(),
            User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User'),
            [new Scope('profile')],
            null,
            new \DateInterval('PT3600S'),
            $chainId,
            dpopJkt: self::JKT,
        );

        return $this->store(RefreshToken::issue($accessToken, $chainId, new \DateInterval('PT2592000S')));
    }

    private function activeClients(): ClientRepositoryInterface
    {
        $clients = $this->createStub(ClientRepositoryInterface::class);
        $clients->method('lockActiveClientForIssuance')->willReturnCallback(fn (): bool => $this->clientActiveAtIssuance);

        return $clients;
    }

    private function users(): UserRepositoryInterface
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?User => isset($this->deletedUserIds[$id->toString()]) ? null : User::reconstitute(new UserState(
            id: $id,
            publicId: new PublicId(),
            name: 'Test User',
            email: 'user@baander.app',
            password: 'hashed-pw',
            totpSecret: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            disabled: isset($this->disabledUserIds[$id->toString()]),
        )));

        return $users;
    }

    private function createConfidentialClient(): Client
    {
        return Client::create(
            name: 'Test App',
            redirectUris: ['http://localhost'],
            secret: ClientSecret::fromString('test-secret'),
            confidential: true,
            firstParty: true,
        );
    }
}
