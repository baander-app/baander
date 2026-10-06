<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RefreshTokenCommand;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Application\CommandHandler\OAuth\RefreshTokenHandler;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Service\TokenChainValidator;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RefreshTokenTransactionTest extends TestCase
{
    #[DataProvider('failureStages')]
    public function testFailureRollsBackConsumptionAndReplacement(string $stage): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE token_state (used INTEGER NOT NULL)');
        $connection->insert('token_state', ['used' => 0]);
        $connection->executeStatement('CREATE TABLE replacement_tokens (id INTEGER PRIMARY KEY)');
        $client = Client::create('test', ['https://app.baander.app'], secret: 'secret', confidential: true, firstParty: true);
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
        $manager->expects($this->once())->method('clear');
        $manager->method('flush')->willReturnCallback(static function () use ($connection, $stage): void {
            $connection->insert('replacement_tokens', ['id' => 1]);
            if ($stage === 'persist') {
                throw new RuntimeException('persist failed');
            }
        });
        $jwt = $this->createStub(JwtGeneratorInterface::class);
        $jwt->method('generate')->willThrowException(new RuntimeException('sign failed'));
        $handler = new RefreshTokenHandler($access, $refresh, new TokenChainValidator($access, $refresh), $manager, $jwt, $this->createStub(TokenMetadataRepositoryInterface::class), 3600, 86400);
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
        $connection->close();
    }

    /** @return iterable<array{string}> */
    public static function failureStages(): iterable
    {
        yield ['persist'];
        yield ['sign'];
    }
}
