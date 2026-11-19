<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Exception\OAuthClientRateLimitedException;
use App\Auth\Application\Port\ClientAssertionVerifierInterface;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\Port\OAuthClientThrottleInterface;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Application\Service\ChainConnectionCloser;
use App\Auth\Application\Service\TokenChainRevoker;
use App\Auth\Application\Service\TokenPairIssuer;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Tests\Fixtures\Catalog\PassThroughTransaction;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

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
    /** @var list<string> Chains revoked, in order */
    private array $revokedChains = [];
    /** @var list<array{string, string, ?string}> Per-client charges: endpoint, client public ID, caller address */
    private array $clientCharges = [];
    /** When set, every per-client charge is refused with this Retry-After. */
    private ?int $clientAllowanceSpentFor = null;

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
        // The issuing transaction bounds its lock waits on the manager's connection.
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($this->createStub(Connection::class));
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

        $transaction = new PassThroughTransaction();

        return new TokenPairIssuer($accessTokens, $refreshTokens, new ScopeAllowlist(['profile', 'email', 'library', 'playlist']), $entityManager, $jwt, $metadata, $clients, $transaction, 3600, 2592000);
    }

    /** Records each revoked chain; no web server runs, so no socket closes. */
    private function chainRevoker(): TokenChainRevoker
    {
        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('revokeByChainId')->willReturnCallback(function (ChainId $chainId): void {
            $this->revokedChains[] = $chainId->toString();
        });
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('findTokenIdsByChainId')->willReturn([]);

        return new TokenChainRevoker(
            $refreshTokens,
            $accessTokens,
            new PassThroughTransaction(),
            new ChainConnectionCloser($this->createStub(LiveConnectionsPortInterface::class), new NullLogger()),
        );
    }

    private function clientAuthenticator(): OAuthClientAuthenticator
    {
        $repository = $this->createStub(ClientRepositoryInterface::class);
        $repository->method('findClientByPublicId')->willReturnCallback(
            fn (PublicId $publicId): ?Client => $this->clients[$publicId->toString()] ?? null,
        );

        return new OAuthClientAuthenticator($repository, $this->createStub(ClientAssertionVerifierInterface::class));
    }

    private function clientThrottle(): OAuthClientThrottleInterface
    {
        $charge = function (string $endpoint, Client $client, ?string $ipAddress): void {
            if ($this->clientAllowanceSpentFor !== null) {
                throw new OAuthClientRateLimitedException($this->clientAllowanceSpentFor);
            }
            $this->clientCharges[] = [$endpoint, $client->getPublicId()->toString(), $ipAddress];
        };
        $throttle = $this->createStub(OAuthClientThrottleInterface::class);
        $throttle->method('chargeTokenRequest')->willReturnCallback(fn (Client $client, ?string $ip) => $charge('token', $client, $ip));
        $throttle->method('chargePushedRequest')->willReturnCallback(fn (Client $client, ?string $ip) => $charge('par', $client, $ip));

        return $throttle;
    }

    private function register(Client $client): Client
    {
        $this->clients[$client->getPublicId()->toString()] = $client;

        return $client;
    }
}
