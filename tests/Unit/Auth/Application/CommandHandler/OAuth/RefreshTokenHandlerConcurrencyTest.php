<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\CommandHandler\OAuth\RefreshTokenHandler;
use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\Exception\RefreshTokenRejectedException;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\RefreshTokenState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Domain\Model\Email;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Two requests present one refresh token at once. Both read it unused before the
 * transaction; one consumes it with the conditional UPDATE and the other, whose UPDATE
 * changed no row, re-reads the token and its replacements under the row lock and gets
 * one of the locked verdicts (R3). This test plays the second request with repository
 * stubs that answer as the committed winner left the rows.
 */
final class RefreshTokenHandlerConcurrencyTest extends TestCase
{
    private const string JKT = 'bound-proof-key-thumbprint';

    private AccessTokenRepositoryInterface&Stub $accessTokenRepository;
    private RefreshTokenRepositoryInterface&Stub $refreshTokenRepository;
    private RefreshTokenHandler $handler;
    private Client $client;
    private User $user;
    private ChainId $chainId;
    private string $tokenIdString;
    private Uuid $presentedRowId;
    /** @var list<RefreshToken> What the locked read finds as the replacements of the presented token. */
    private array $successorsUnderLock = [];
    private int $consumeCallCount = 0;
    /** @var list<string> */
    private array $revokedRefreshIds = [];
    /** @var list<string> */
    private array $revokedChains = [];

    protected function setUp(): void
    {
        $this->client = $this->createConfidentialClient();
        $this->user = User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User');
        $this->chainId = ChainId::generate();
        $this->tokenIdString = 'same-refresh-token-id-used-twice-concurrently';
        $this->presentedRowId = Uuid::generate();

        $this->accessTokenRepository = $this->createStub(AccessTokenRepositoryInterface::class);
        $this->refreshTokenRepository = $this->createStub(RefreshTokenRepositoryInterface::class);
        $this->accessTokenRepository->method('revokeByIds')->willReturnCallback(static fn (array $ids): int => count($ids));
        $this->refreshTokenRepository->method('revokeByIds')->willReturnCallback(function (array $ids): int {
            foreach ($ids as $id) {
                $this->revokedRefreshIds[] = $id->toString();
            }

            return count($ids);
        });
        $this->refreshTokenRepository->method('revokeByChainId')->willReturnCallback(function (ChainId $chainId): void {
            $this->revokedChains[] = 'refresh ' . $chainId->toString();
        });
        $this->accessTokenRepository->method('revokeByChainId')->willReturnCallback(function (ChainId $chainId): void {
            $this->revokedChains[] = 'access ' . $chainId->toString();
        });

        // Simulate two concurrent requests reading the same DB row before either
        // writes. Each invocation must get its own object so that in-memory
        // mutation from the first call does not hide the bug.
        $this->refreshTokenRepository
            ->method('findByTokenId')
            ->willReturnCallback(fn (): RefreshToken => $this->presentedToken(usedAt: null));

        $this->consumeCallCount = 0;
        $this->refreshTokenRepository
            ->method('consumeByTokenId')
            ->willReturnCallback(function (): ?RefreshToken {
                $this->consumeCallCount++;
                if ($this->consumeCallCount > 1) {
                    return null;
                }

                return $this->presentedToken(usedAt: new \DateTimeImmutable());
            });

        // The locked read sees the row as the winner's commit left it: consumed just now.
        $this->refreshTokenRepository
            ->method('lockByTokenId')
            ->willReturnCallback(fn (): RefreshToken => $this->presentedToken(usedAt: new \DateTimeImmutable()));
        $this->refreshTokenRepository
            ->method('lockSuccessors')
            ->willReturnCallback(fn (): array => $this->successorsUnderLock);
        $this->refreshTokenRepository->method('consumedWithin')->willReturn(true);

        $chainValidator = new TokenChainValidator(
            $this->createStub(AccessTokenRepositoryInterface::class),
            $this->createStub(RefreshTokenRepositoryInterface::class),
        );

        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation) => $operation());
        $entityManager = $this->createStub(EntityManagerInterface::class);

        $jwtGenerator = $this->createStub(JwtGeneratorInterface::class);
        $jwtGenerator->method('generate')->willReturn('mock-jwt');

        $this->handler = new RefreshTokenHandler(
            $this->accessTokenRepository,
            $this->refreshTokenRepository,
            $chainValidator,
            $entityManager,
            $jwtGenerator,
            $this->createStub(TokenMetadataRepositoryInterface::class),
            $this->activeClients(),
            $this->users(),
            $transaction,
            new NullLogger(),
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
            reuseGraceSeconds: 60,
        );
    }

    /** AE3: the loser of the consume race supersedes the winner's unused pair and gets its own. */
    public function testTheConsumeRaceLoserGetsAFreshPairWhenTheWinnersReplacementIsUnused(): void
    {
        $command = new RefreshTokenCommand(refreshTokenId: $this->tokenIdString, dpopJkt: self::JKT);

        // First request succeeds.
        $first = ($this->handler)($command);
        $this->assertNotEmpty($first->getAccessToken());
        $this->assertNotEmpty($first->getRefreshToken());

        // The second request's conditional UPDATE changes no row; under the lock it
        // finds the token consumed and the winner's replacement unused.
        $replacement = $this->replacement();
        $this->successorsUnderLock = [$replacement];

        $second = ($this->handler)($command);

        $this->assertNotEmpty($second->getRefreshToken());
        self::assertNotSame($first->getRefreshToken(), $second->getRefreshToken());
        self::assertSame([$replacement->getId()->toString()], $this->revokedRefreshIds, 'The winner\'s pair is superseded: one usable pair remains.');
        self::assertSame([], $this->revokedChains);
    }

    /** The loser is rejected and the chain revoked when the winner's replacement was already used. */
    public function testTheConsumeRaceLoserIsRejectedWhenTheWinnersReplacementWasUsed(): void
    {
        $command = new RefreshTokenCommand(refreshTokenId: $this->tokenIdString, dpopJkt: self::JKT);
        ($this->handler)($command);
        $replacement = $this->replacement();
        $replacement->markUsed();
        $this->successorsUnderLock = [$replacement];

        try {
            ($this->handler)($command);
            self::fail('A replacement already used makes the presentation a replay.');
        } catch (RefreshTokenRejectedException $exception) {
            self::assertSame('Refresh token reuse detected: a replacement was already used.', $exception->getMessage());
        }
        self::assertSame(['refresh ' . $this->chainId->toString(), 'access ' . $this->chainId->toString()], $this->revokedChains, 'Refresh rows before access rows.');
        self::assertSame([], $this->revokedRefreshIds);
    }

    /** The loser is rejected without revoking when a competing grace already revoked every replacement. */
    public function testTheConsumeRaceLoserIsRejectedWithTheChainIntactWhenEveryReplacementIsRevoked(): void
    {
        $command = new RefreshTokenCommand(refreshTokenId: $this->tokenIdString, dpopJkt: self::JKT);
        ($this->handler)($command);
        $replacement = $this->replacement();
        $replacement->revoke();
        $this->successorsUnderLock = [$replacement];

        try {
            ($this->handler)($command);
            self::fail('Nothing is left to supersede.');
        } catch (RefreshTokenRejectedException $exception) {
            self::assertSame('Refresh token was consumed by a concurrent request.', $exception->getMessage());
        }
        self::assertSame([], $this->revokedChains);
        self::assertSame([], $this->revokedRefreshIds);
    }

    private function presentedToken(?\DateTimeImmutable $usedAt): RefreshToken
    {
        $accessToken = AccessToken::issue(
            $this->client,
            $this->user,
            [new Scope('profile')],
            null,
            null,
            $this->chainId,
            dpopJkt: self::JKT,
        );

        return RefreshToken::reconstitute(new RefreshTokenState(
            id: $this->presentedRowId,
            tokenId: TokenId::fromString($this->tokenIdString),
            accessToken: $accessToken,
            chainId: $this->chainId,
            previousRefreshToken: null,
            expiresAt: null,
            usedAt: $usedAt,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            revoked: false,
        ));
    }

    private function replacement(): RefreshToken
    {
        $accessToken = AccessToken::issue($this->client, $this->user, [new Scope('profile')], null, null, $this->chainId, dpopJkt: self::JKT);

        return RefreshToken::issue($accessToken, $this->chainId, null, $this->presentedToken(usedAt: new \DateTimeImmutable()));
    }

    /** Every client is still active when its row is locked for issuance. */
    private function users(): UserRepositoryInterface
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn(User::register(new Email('user@baander.app'), 'hashed-pw', 'Test User'));

        return $users;
    }

    private function activeClients(): ClientRepositoryInterface
    {
        $clients = $this->createStub(ClientRepositoryInterface::class);
        $clients->method('lockActiveClientForIssuance')->willReturn(true);

        return $clients;
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
