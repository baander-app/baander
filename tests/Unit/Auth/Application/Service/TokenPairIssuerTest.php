<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\Service;

use App\Auth\Application\Exception\OAuthProtocolException;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Application\Service\IssuanceLockTimeout;
use App\Auth\Application\Service\TokenPairIssuer;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Issuance writes the pair, its metadata and the signature inside one transaction, in
 * the order flush, metadata, sign, commit (KTD10), with the lock waits bounded (KTD11).
 */
final class TokenPairIssuerTest extends TestCase
{
    private const string JKT = 'proof-key-thumbprint';

    /**
     * Every step in the order it ran, with the transaction boundaries.
     *
     * @var list<string>
     */
    private array $events = [];
    private bool $clientActiveAtIssuance = true;
    private bool $signingFails = false;
    private TokenPairIssuer $issuer;

    protected function setUp(): void
    {
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('save')->willReturnCallback(function (AccessToken $token, bool $flush): void {
            $this->events[] = $flush ? 'access token saved and flushed' : 'access token saved';
        });
        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('save')->willReturnCallback(function (RefreshToken $token, bool $flush): void {
            $this->events[] = $flush ? 'refresh token saved and flushed' : 'refresh token saved';
        });
        $clients = $this->createStub(ClientRepositoryInterface::class);
        $clients->method('lockActiveClientForIssuance')->willReturnCallback(function (): bool {
            $this->events[] = 'client locked';

            return $this->clientActiveAtIssuance;
        });

        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->events[] = $sql;

            return 0;
        });
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->events[] = 'flushed';
        });

        $jwt = $this->createStub(JwtGeneratorInterface::class);
        $jwt->method('generate')->willReturnCallback(function (): string {
            if ($this->signingFails) {
                $this->events[] = 'signing failed';

                throw new RuntimeException('injected signing failure');
            }
            $this->events[] = 'signed';

            return 'header.payload.signature';
        });
        $metadata = $this->createStub(TokenMetadataRepositoryInterface::class);
        $metadata->method('save')->willReturnCallback(function (TokenMetadata $entry): void {
            $this->events[] = 'metadata saved';
        });

        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->events[] = 'begin';
            try {
                $result = $operation();
            } catch (Throwable $failure) {
                $this->events[] = 'rolled back';

                throw $failure;
            }
            $this->events[] = 'committed';

            return $result;
        });

        $this->issuer = new TokenPairIssuer(
            accessTokenRepository: $accessTokens,
            refreshTokenRepository: $refreshTokens,
            scopeAllowlist: new ScopeAllowlist(['profile']),
            entityManager: $entityManager,
            jwtGenerator: $jwt,
            tokenMetadataRepository: $metadata,
            clientRepository: $clients,
            transaction: $transaction,
            accessTokenTtl: 3600,
            refreshTokenTtl: 2592000,
        );
    }

    public function testTheMetadataAndTheSignatureAreWrittenInsideTheTransactionAfterTheFlush(): void
    {
        $response = $this->issuer->issue($this->client(), $this->user(), ['profile'], self::JKT, persistWithTokens: function (): void {
            $this->events[] = 'grant consumed';
        });

        self::assertSame('header.payload.signature', $response->getAccessToken());
        self::assertSame([
            'begin',
            sprintf("SET LOCAL lock_timeout = '%dms'", IssuanceLockTimeout::MILLISECONDS),
            'client locked',
            'grant consumed',
            'access token saved',
            'refresh token saved',
            'flushed',
            'metadata saved',
            'signed',
            'committed',
        ], $this->events);
    }

    public function testASigningFailureRollsBackThePairAndItsMetadata(): void
    {
        $this->signingFails = true;

        try {
            $this->issuer->issue($this->client(), $this->user(), ['profile'], self::JKT);
            self::fail('The signing failure must propagate.');
        } catch (RuntimeException $failure) {
            self::assertSame('injected signing failure', $failure->getMessage());
        }

        self::assertNotContains('committed', $this->events);
        self::assertSame(['metadata saved', 'signing failed', 'rolled back'], array_slice($this->events, -3), 'The metadata was written inside the transaction that rolled back.');
    }

    public function testAClientRevokedUnderTheLockIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->clientActiveAtIssuance = false;

        try {
            $this->issuer->issue($this->client(), $this->user(), ['profile'], self::JKT);
            self::fail('A revoked client cannot be issued to.');
        } catch (OAuthProtocolException $refusal) {
            self::assertSame('invalid_client', $refusal->error);
        }

        self::assertSame(['client locked', 'rolled back'], array_slice($this->events, -2));
        self::assertNotContains('access token saved', $this->events);
    }

    private function client(): Client
    {
        return Client::create('Browser', ['https://baander.app/callback'], firstParty: true);
    }

    private function user(): User
    {
        return User::register(new Email('issuer@baander.app'), 'hashed', 'Issuer');
    }
}
