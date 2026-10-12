<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\OAuth\ExchangeRefreshTokenCommand;
use App\Auth\Application\CommandHandler\OAuth\ExchangeRefreshTokenHandler;
use App\Auth\Application\CommandHandler\OAuth\RefreshTokenHandler;
use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Application\Port\TransactionPortInterface;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The token endpoint answers invalid_grant only when the refresh grant refuses the
 * token; a server fault propagates, so the client keeps a token that may still be good.
 */
final class ExchangeRefreshTokenHandlerTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = Client::create('Living room TV', ['https://tv.baander.app/callback']);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedTokens(): iterable
    {
        yield 'unknown' => [str_repeat('u', 80)];
        yield 'malformed' => ['short-token'];
    }

    #[DataProvider('refusedTokens')]
    public function testARefusedTokenIsAnInvalidGrant(string $refreshToken): void
    {
        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('findByTokenId')->willReturn(null);

        try {
            ($this->handler($refreshTokens))($this->command($refreshToken));
            self::fail('A refused refresh token must be an invalid_grant.');
        } catch (OAuthProtocolException $exception) {
            self::assertSame('invalid_grant', $exception->error);
            self::assertSame(400, $exception->statusCode);
        }
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function serverFaults(): iterable
    {
        yield 'lost database connection' => [new ConnectionException(
            new class ('connection to server was lost', '08006') extends AbstractException {
            },
            null,
        )];
        yield 'unclassified runtime failure' => [new RuntimeException('Redis went away')];
    }

    #[DataProvider('serverFaults')]
    public function testAServerFaultIsNotAnInvalidGrant(\Throwable $fault): void
    {
        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('findByTokenId')->willThrowException($fault);

        try {
            ($this->handler($refreshTokens))($this->command(str_repeat('r', 80)));
            self::fail('The server fault must propagate.');
        } catch (\Throwable $exception) {
            self::assertSame($fault, $exception);
        }
    }

    private function handler(RefreshTokenRepositoryInterface $refreshTokens): ExchangeRefreshTokenHandler
    {
        $clients = $this->createStub(ClientRepositoryInterface::class);
        $clients->method('findClientByPublicId')->willReturn($this->client);
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);

        return new ExchangeRefreshTokenHandler(
            new OAuthClientAuthenticator($clients),
            new RefreshTokenHandler(
                $accessTokens,
                $refreshTokens,
                new TokenChainValidator($accessTokens, $refreshTokens),
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(JwtGeneratorInterface::class),
                $this->createStub(TokenMetadataRepositoryInterface::class),
                $clients,
                $this->createStub(UserRepositoryInterface::class),
                $this->createStub(TransactionPortInterface::class),
                new NullLogger(),
                accessTokenTtl: 3600,
                refreshTokenTtl: 2592000,
                reuseGraceSeconds: 60,
            ),
        );
    }

    private function command(string $refreshToken): ExchangeRefreshTokenCommand
    {
        return new ExchangeRefreshTokenCommand(
            clientId: $this->client->getPublicId()->toString(),
            clientSecret: null,
            refreshToken: $refreshToken,
            dpopJkt: 'exchange-test-proof-key-thumbprint',
        );
    }
}
