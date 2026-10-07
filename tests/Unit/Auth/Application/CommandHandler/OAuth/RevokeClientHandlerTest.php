<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RevokeClientCommand;
use App\Auth\Application\CommandHandler\OAuth\RevokeClientHandler;
use App\Auth\Application\Exception\ClientNotFoundException;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class RevokeClientHandlerTest extends TestCase
{
    public function testOwnerRevokesClientAndItsTokensInOneTransaction(): void
    {
        $owner = Uuid::generate();
        $client = Client::createPersonalAccess('CLI', $owner);
        $calls = [];
        $clients = $this->createMock(ClientRepositoryInterface::class);
        $clients->expects(self::once())->method('findClientByPublicId')->with($client->getPublicId())->willReturn($client);
        $clients->expects(self::once())->method('saveClient')
            ->willReturnCallback(static function (Client $saved) use ($client, &$calls): void {
                self::assertSame($client, $saved);
                self::assertTrue($saved->isRevoked());
                $calls[] = 'client';
            });
        $refreshTokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $refreshTokens->expects(self::once())->method('revokeByClientId')
            ->willReturnCallback(static function (Uuid $clientId) use ($client, &$calls): void {
                self::assertTrue($clientId->equals($client->getId()));
                $calls[] = 'refresh';
            });
        $accessTokens = $this->createMock(AccessTokenRepositoryInterface::class);
        $accessTokens->expects(self::once())->method('revokeByClientId')
            ->willReturnCallback(static function (Uuid $clientId) use ($client, &$calls): void {
                self::assertTrue($clientId->equals($client->getId()));
                $calls[] = 'access';
            });
        $transaction = $this->createMock(TransactionPortInterface::class);
        $transaction->expects(self::once())->method('run')
            ->willReturnCallback(static function (callable $operation) use (&$calls): mixed {
                $calls[] = 'begin';
                $result = $operation();
                $calls[] = 'commit';

                return $result;
            });

        (new RevokeClientHandler($clients, $accessTokens, $refreshTokens, $transaction))(
            new RevokeClientCommand($owner, $client->getPublicId()),
        );

        self::assertSame(['begin', 'client', 'refresh', 'access', 'commit'], $calls);
    }

    public function testUnknownClientIsNotFound(): void
    {
        $this->expectException(ClientNotFoundException::class);

        $this->handlerWithoutWrites(null)(new RevokeClientCommand(Uuid::generate(), new PublicId()));
    }

    public function testClientOwnedByAnotherUserIsNotFoundAndStaysActive(): void
    {
        $client = Client::createPersonalAccess('Other user', Uuid::generate());

        try {
            $this->handlerWithoutWrites($client)(new RevokeClientCommand(Uuid::generate(), $client->getPublicId()));
            self::fail('Revoking another user\'s client must fail.');
        } catch (ClientNotFoundException) {
        }

        self::assertFalse($client->isRevoked());
    }

    public function testUnownedSystemClientIsNotFound(): void
    {
        $client = Client::create('First-party SPA', ['https://baander.app/callback'], firstParty: true);

        $this->expectException(ClientNotFoundException::class);

        $this->handlerWithoutWrites($client)(new RevokeClientCommand(Uuid::generate(), $client->getPublicId()));
    }

    public function testTokenRevocationFailurePropagatesFromTheTransaction(): void
    {
        $owner = Uuid::generate();
        $client = Client::createPersonalAccess('CLI', $owner);
        $failure = new \RuntimeException('Database unavailable');
        $clients = $this->createStub(ClientRepositoryInterface::class);
        $clients->method('findClientByPublicId')->willReturn($client);
        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('revokeByClientId')->willThrowException($failure);
        $accessTokens = $this->createMock(AccessTokenRepositoryInterface::class);
        $accessTokens->expects(self::never())->method('revokeByClientId');
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $this->expectExceptionObject($failure);

        (new RevokeClientHandler($clients, $accessTokens, $refreshTokens, $transaction))(
            new RevokeClientCommand($owner, $client->getPublicId()),
        );
    }

    private function handlerWithoutWrites(?Client $found): RevokeClientHandler
    {
        $clients = $this->createMock(ClientRepositoryInterface::class);
        $clients->expects(self::once())->method('findClientByPublicId')->willReturn($found);
        $clients->expects(self::never())->method('saveClient');
        $accessTokens = $this->createMock(AccessTokenRepositoryInterface::class);
        $accessTokens->expects(self::never())->method('revokeByClientId');
        $refreshTokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $refreshTokens->expects(self::never())->method('revokeByClientId');
        $transaction = $this->createMock(TransactionPortInterface::class);
        $transaction->expects(self::never())->method('run');

        return new RevokeClientHandler($clients, $accessTokens, $refreshTokens, $transaction);
    }
}
