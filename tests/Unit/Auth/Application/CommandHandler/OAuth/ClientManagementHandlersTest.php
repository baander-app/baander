<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RegisterClientCommand;
use App\Auth\Application\Command\OAuth\RevokeRegisteredClientCommand;
use App\Auth\Application\Command\OAuth\RotateClientSecretCommand;
use App\Auth\Application\CommandHandler\OAuth\RegisterClientHandler;
use App\Auth\Application\CommandHandler\OAuth\RevokeRegisteredClientHandler;
use App\Auth\Application\CommandHandler\OAuth\RotateClientSecretHandler;
use App\Auth\Application\Exception\ClientManagementException;
use App\Auth\Application\Exception\ClientNotFoundException;
use App\Auth\Application\Service\ClientRevoker;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientType;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Administrator client registration, secret rotation and revocation. */
final class ClientManagementHandlersTest extends TestCase
{
    /** @var array<string, Client> */
    private array $saved = [];
    /** @var list<string> */
    private array $revokedTokensOf = [];

    public function testConfidentialRegistrationReturnsTheSecretOnceAndStoresItsDigest(): void
    {
        $registered = ($this->register())(new RegisterClientCommand('Server app', 'confidential', ['https://server.baander.app/callback']));

        self::assertIsString($registered->secret);
        self::assertSame(ClientType::Confidential, $registered->client->getType());
        self::assertSame(hash('sha256', $registered->secret), $registered->client->getSecretHash());
        self::assertSame($registered->client, $this->saved[$registered->client->getPublicId()->toString()]);
        $authenticated = (new OAuthClientAuthenticator($this->repository()))->authenticate($registered->client->getPublicId()->toString(), $registered->secret);
        self::assertSame($registered->client, $authenticated);
    }

    public function testDeviceAndPublicRegistrationsHaveNoSecret(): void
    {
        $device = ($this->register())(new RegisterClientCommand('Living room TV', 'device'));
        $public = ($this->register())(new RegisterClientCommand('Player', 'public', ['app.baander.player:/callback']));

        self::assertNull($device->secret);
        self::assertSame(ClientType::Device, $device->client->getType());
        self::assertTrue($device->client->isDeviceClient());
        self::assertNull($public->secret);
        self::assertSame(ClientType::Public, $public->client->getType());
        self::assertNull($public->client->getSecretHash());
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function invalidRegistrations(): iterable
    {
        yield 'first-party type' => ['first_party', ['https://app.baander.app/callback']];
        yield 'unknown type' => ['implicit', ['https://app.baander.app/callback']];
        yield 'device with redirect URIs' => ['device', ['https://tv.baander.app/callback']];
        yield 'public without redirect URIs' => ['public', []];
        yield 'confidential with an http redirect URI' => ['confidential', ['http://server.baander.app/callback']];
    }

    /** @param list<string> $redirectUris */
    #[DataProvider('invalidRegistrations')]
    public function testInvalidRegistrationsAreRejectedUnsaved(string $type, array $redirectUris): void
    {
        try {
            ($this->register())(new RegisterClientCommand('App', $type, $redirectUris));
            self::fail('The registration must be rejected.');
        } catch (ClientManagementException $exception) {
            self::assertSame(ClientManagementException::INVALID_REGISTRATION, $exception->reason);
        }

        self::assertSame([], $this->saved);
    }

    public function testRotationReplacesTheSecret(): void
    {
        $client = $this->stored(Client::registerConfidential('Server', ['https://server.baander.app/cb'], ClientSecret::fromString('old-secret')));

        $rotated = (new RotateClientSecretHandler($this->repository()))(new RotateClientSecretCommand($client->getPublicId()));

        self::assertIsString($rotated->secret);
        self::assertTrue($client->authenticatesWith($rotated->secret));
        self::assertFalse($client->authenticatesWith('old-secret'));
    }

    /** @return iterable<string, array{Client, string}> */
    public static function unrotatableClients(): iterable
    {
        yield 'first-party SPA' => [Client::create('SPA', ['http://localhost'], firstParty: true, passwordClient: true), ClientManagementException::PROTECTED_CLIENT];
        yield 'personal access' => [Client::createPersonalAccess('CLI', Uuid::generate()), ClientManagementException::PROTECTED_CLIENT];
        yield 'public' => [Client::registerPublic('Player', ['https://player.baander.app/cb']), ClientManagementException::NO_SECRET];
        yield 'device' => [Client::registerDevice('TV'), ClientManagementException::NO_SECRET];
    }

    #[DataProvider('unrotatableClients')]
    public function testOnlyActiveConfidentialClientsRotate(Client $client, string $reason): void
    {
        $this->stored($client);

        try {
            (new RotateClientSecretHandler($this->repository()))(new RotateClientSecretCommand($client->getPublicId()));
            self::fail('The rotation must be rejected.');
        } catch (ClientManagementException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    public function testRevocationRevokesTheClientAndItsTokens(): void
    {
        $client = $this->stored(Client::registerDevice('TV'));

        $revoked = $this->revoker()(new RevokeRegisteredClientCommand($client->getPublicId()));

        self::assertTrue($revoked->isRevoked());
        self::assertSame(['refresh:' . $client->getId(), 'access:' . $client->getId()], $this->revokedTokensOf);
    }

    public function testTheFirstPartyClientCannotBeRevoked(): void
    {
        $spa = $this->stored(Client::create('SPA', ['http://localhost'], firstParty: true, passwordClient: true));

        try {
            $this->revoker()(new RevokeRegisteredClientCommand($spa->getPublicId()));
            self::fail('The first-party client must be protected.');
        } catch (ClientManagementException $exception) {
            self::assertSame(ClientManagementException::PROTECTED_CLIENT, $exception->reason);
        }

        self::assertFalse($spa->isRevoked());
        self::assertSame([], $this->revokedTokensOf);
    }

    public function testUnknownClientsAreNotFound(): void
    {
        $this->expectException(ClientNotFoundException::class);

        $this->revoker()(new RevokeRegisteredClientCommand(new PublicId()));
    }

    private function register(): RegisterClientHandler
    {
        return new RegisterClientHandler($this->repository());
    }

    private function revoker(): RevokeRegisteredClientHandler
    {
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('revokeByClientId')->willReturnCallback(function (Uuid $id): void {
            $this->revokedTokensOf[] = 'access:' . $id;
        });
        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('revokeByClientId')->willReturnCallback(function (Uuid $id): void {
            $this->revokedTokensOf[] = 'refresh:' . $id;
        });
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return new RevokeRegisteredClientHandler(
            $this->repository(),
            new ClientRevoker($this->repository(), $accessTokens, $refreshTokens, $transaction),
        );
    }

    private function stored(Client $client): Client
    {
        $this->saved[$client->getPublicId()->toString()] = $client;

        return $client;
    }

    private function repository(): ClientRepositoryInterface
    {
        $repository = $this->createStub(ClientRepositoryInterface::class);
        $repository->method('saveClient')->willReturnCallback(function (Client $client): void {
            $this->saved[$client->getPublicId()->toString()] = $client;
        });
        $repository->method('findClientByPublicId')->willReturnCallback(
            fn (PublicId $id): ?Client => $this->saved[$id->toString()] ?? null,
        );

        return $repository;
    }
}
