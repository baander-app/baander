<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Application\Service\TokenPairIssuer;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An in-memory token pair issuer and client registry for grant handler tests.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait IssuesTokenPairs
{
    /** @var list<AccessToken> */
    private array $issuedAccessTokens = [];
    /** @var list<RefreshToken> */
    private array $issuedRefreshTokens = [];
    /** @var list<TokenMetadata> */
    private array $issuedMetadata = [];
    /** @var list<?string> */
    private array $jwtBindings = [];
    /** @var array<string, Client> */
    private array $clients = [];

    private function tokenPairIssuer(): TokenPairIssuer
    {
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('save')->willReturnCallback(function (AccessToken $token): void {
            $this->issuedAccessTokens[] = $token;
        });
        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('save')->willReturnCallback(function (RefreshToken $token): void {
            $this->issuedRefreshTokens[] = $token;
        });
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $jwt = $this->createStub(JwtGeneratorInterface::class);
        $jwt->method('generate')->willReturnCallback(function (AccessToken $token, ?string $jkt): string {
            $this->jwtBindings[] = $jkt;

            return 'header.payload.signature';
        });
        $metadata = $this->createStub(TokenMetadataRepositoryInterface::class);
        $metadata->method('save')->willReturnCallback(function (TokenMetadata $entry): void {
            $this->issuedMetadata[] = $entry;
        });

        $clients = $this->createStub(ClientRepositoryInterface::class);
        $clients->method('lockActiveClientForIssuance')->willReturnCallback(function (Uuid $clientId): bool {
            // The registered client's current state stands for its locked row.
            foreach ($this->clients as $client) {
                if ($client->getId()->equals($clientId)) {
                    return !$client->isRevoked();
                }
            }

            return false;
        });

        return new TokenPairIssuer($accessTokens, $refreshTokens, new ScopeAllowlist(['profile', 'email', 'library', 'playlist']), $entityManager, $jwt, $metadata, $clients, 3600, 2592000);
    }

    private function clientAuthenticator(): OAuthClientAuthenticator
    {
        $repository = $this->createStub(ClientRepositoryInterface::class);
        $repository->method('findClientByPublicId')->willReturnCallback(
            fn (PublicId $publicId): ?Client => $this->clients[$publicId->toString()] ?? null,
        );

        return new OAuthClientAuthenticator($repository);
    }

    private function register(Client $client): Client
    {
        $this->clients[$client->getPublicId()->toString()] = $client;

        return $client;
    }
}
