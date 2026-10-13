<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\CommandHandler\OAuth\RefreshTokenHandler;
use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\Service\IssuanceLockTimeout;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Infrastructure\Doctrine\DoctrineTransaction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;

/**
 * The rotation is one transaction over a real connection: a failure after the consume
 * rolls the consumption and the replacement back, and the transaction bounds its lock
 * waits before it takes the first lock (KTD11).
 */
final class RefreshTokenTransactionTest extends TestCase
{
    #[DataProvider('failureStages')]
    public function testFailureRollsBackConsumptionAndReplacement(string $stage): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => LocalSettingsRecordingConnection::class]);
        self::assertInstanceOf(LocalSettingsRecordingConnection::class, $connection);
        $connection->executeStatement('CREATE TABLE token_state (used INTEGER NOT NULL)');
        $connection->insert('token_state', ['used' => 0]);
        $connection->executeStatement('CREATE TABLE replacement_tokens (id INTEGER PRIMARY KEY)');
        $client = Client::create('test', ['https://app.baander.app'], secret: ClientSecret::fromString('secret'), confidential: true, firstParty: true);
        $token = RefreshToken::issue(AccessToken::issue($client, null, [], null, dpopJkt: 'bound-proof-key-thumbprint'), null);
        $refresh = $this->createMock(RefreshTokenRepositoryInterface::class);
        $refresh->method('findByTokenId')->willReturn($token);
        $refresh->expects($this->once())->method('consumeByTokenId')->willReturnCallback(static function () use ($connection, $token): RefreshToken {
            $connection->executeStatement('UPDATE token_state SET used = 1 WHERE used = 0');
            $token->markUsed();
            return $token;
        });
        $access = $this->createStub(AccessTokenRepositoryInterface::class);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn(true);
        $manager->expects($this->once())->method('clear');
        $manager->method('flush')->willReturnCallback(static function () use ($connection, $stage): void {
            $connection->insert('replacement_tokens', ['id' => 1]);
            if ($stage === 'persist') {
                throw new RuntimeException('persist failed');
            }
        });
        $jwt = $this->createStub(JwtGeneratorInterface::class);
        $jwt->method('generate')->willThrowException(new RuntimeException('sign failed'));
        $clients = $this->createMock(ClientRepositoryInterface::class);
        $clients->expects($this->once())->method('lockActiveClientForIssuance')->willReturnCallback(static function () use ($connection): bool {
            // The lock wait is bounded before the first lock of the transaction is taken.
            self::assertSame([sprintf("SET LOCAL lock_timeout = '%dms'", IssuanceLockTimeout::MILLISECONDS)], $connection->localSettingsInTransaction);

            return true;
        });
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $handler = new RefreshTokenHandler(
            accessTokenRepository: $access,
            refreshTokenRepository: $refresh,
            chainValidator: new TokenChainValidator(),
            entityManager: $manager,
            jwtGenerator: $jwt,
            tokenMetadataRepository: $this->createStub(TokenMetadataRepositoryInterface::class),
            clientRepository: $clients,
            userRepository: $this->createStub(UserRepositoryInterface::class),
            transaction: new DoctrineTransaction($registry, new Swoole()),
            logger: new NullLogger(),
            liveConnections: $this->createStub(LiveConnectionsPortInterface::class),
            accessTokenTtl: 3600,
            refreshTokenTtl: 86400,
            reuseGraceSeconds: 60,
        );
        $command = new RefreshTokenCommand($token->getTokenId()->toString(), dpopJkt: 'bound-proof-key-thumbprint');
        try {
            $handler($command);
            self::fail('Expected rotation to fail');
        } catch (RuntimeException $exception) {
            self::assertSame($stage === 'persist' ? 'persist failed' : 'sign failed', $exception->getMessage());
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT used FROM token_state'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM replacement_tokens'));
        self::assertFalse($connection->isTransactionActive());
        self::assertCount(1, $connection->localSettingsInTransaction);
        $connection->close();
    }

    /** @return iterable<array{string}> */
    public static function failureStages(): iterable
    {
        yield ['persist'];
        yield ['sign'];
    }
}

/** SQLite has no lock_timeout; the transaction-local settings are recorded instead, with the transaction they were set in. */
final class LocalSettingsRecordingConnection extends Connection
{
    /** @var list<string> */
    public array $localSettingsInTransaction = [];

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (str_starts_with($sql, 'SET LOCAL ')) {
            if (!$this->isTransactionActive()) {
                throw new RuntimeException('SET LOCAL outside a transaction has no effect: ' . $sql);
            }
            $this->localSettingsInTransaction[] = $sql;

            return 0;
        }

        return parent::executeStatement($sql, $params, $types);
    }
}
