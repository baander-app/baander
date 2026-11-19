<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\RegisterClientCommand;
use App\Auth\Application\Command\OAuth\ReplaceClientKeysCommand;
use App\Auth\Application\Command\OAuth\RevokeRegisteredClientCommand;
use App\Auth\Application\Command\OAuth\RotateClientSecretCommand;
use App\Auth\Application\CommandHandler\OAuth\RegisterClientHandler;
use App\Auth\Application\CommandHandler\OAuth\ReplaceClientKeysHandler;
use App\Auth\Application\CommandHandler\OAuth\RevokeRegisteredClientHandler;
use App\Auth\Application\CommandHandler\OAuth\RotateClientSecretHandler;
use App\Auth\Application\DTO\ClientCredentials;
use App\Auth\Application\Port\ClientAssertionVerifierInterface;
use App\Auth\Application\Service\ClientRevoker;
use App\Auth\Application\Service\OAuthClientAuthenticator;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientAuthMethod;
use App\Auth\Domain\Model\OAuth\ClientType;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Fixtures\Auth\SignedClientAssertion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Administrator client registration, secret rotation, key replacement and revocation. */
final class ClientManagementHandlersTest extends TestCase
{
    private const string CALLBACK = 'https://server.baander.app/callback';
    private const string JWKS_URI = 'https://server.baander.app/jwks.json';

    /** @var array<string, Client> */
    private array $saved = [];
    /** @var list<string> */
    private array $revokedTokensOf = [];

    public function testConfidentialRegistrationReturnsTheSecretOnceAndStoresItsDigest(): void
    {
        $registered = ($this->register())(new RegisterClientCommand('Server app', 'confidential', [self::CALLBACK]));

        self::assertIsString($registered->secret);
        self::assertSame(ClientType::Confidential, $registered->client->getType());
        self::assertSame(ClientAuthMethod::ClientSecretPost, $registered->client->getAuthMethod());
        self::assertSame(hash('sha256', $registered->secret), $registered->client->getSecretHash());
        self::assertSame($registered->client, $this->saved[$registered->client->getPublicId()->toString()]);
        $authenticated = (new OAuthClientAuthenticator($this->repository(), $this->createStub(ClientAssertionVerifierInterface::class)))
            ->authenticate(new ClientCredentials($registered->client->getPublicId()->toString(), $registered->secret));
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
        $this->assertInvalidRegistration(new RegisterClientCommand('App', $type, $redirectUris));
    }

    public function testAKeyClientRegistersWithAnInlineKeySetAndNoSecret(): void
    {
        $key = new SignedClientAssertion();

        $registered = ($this->register())(new RegisterClientCommand('Signing server', 'confidential', [self::CALLBACK], 'private_key_jwt', jwks: $key->jwks()));

        self::assertNull($registered->secret);
        self::assertSame(ClientType::Confidential, $registered->client->getType());
        self::assertSame(ClientAuthMethod::PrivateKeyJwt, $registered->client->getAuthMethod());
        self::assertNull($registered->client->getSecretHash());
        self::assertSame($key->jwks(), $registered->client->getKeySource()?->jwks());
        self::assertSame($registered->client, $this->saved[$registered->client->getPublicId()->toString()]);
    }

    public function testAKeyClientRegistersWithAJwksUri(): void
    {
        $registered = ($this->register())(new RegisterClientCommand('Rotating server', 'confidential', [self::CALLBACK], 'private_key_jwt', jwksUri: self::JWKS_URI));

        self::assertNull($registered->secret);
        self::assertSame(self::JWKS_URI, $registered->client->getKeySource()?->jwksUri());
        self::assertNull($registered->client->getKeySource()->jwks());
    }

    /** @return iterable<string, array{RegisterClientCommand}> */
    public static function invalidKeyRegistrations(): iterable
    {
        $jwks = (new SignedClientAssertion())->jwks();
        $uris = [self::CALLBACK];

        yield 'neither key source' => [new RegisterClientCommand('App', 'confidential', $uris, 'private_key_jwt')];
        yield 'both key sources' => [new RegisterClientCommand('App', 'confidential', $uris, 'private_key_jwt', $jwks, self::JWKS_URI)];
        yield 'a key set with a private key' => [new RegisterClientCommand('App', 'confidential', $uris, 'private_key_jwt', ['keys' => [[...$jwks['keys'][0], 'd' => 'private']]])];
        yield 'an http jwks_uri' => [new RegisterClientCommand('App', 'confidential', $uris, 'private_key_jwt', jwksUri: 'http://server.baander.app/jwks.json')];
        yield 'a jwks_uri naming an IP address' => [new RegisterClientCommand('App', 'confidential', $uris, 'private_key_jwt', jwksUri: 'https://10.0.0.5/jwks.json')];
        yield 'a jwks_uri naming a single-label host' => [new RegisterClientCommand('App', 'confidential', $uris, 'private_key_jwt', jwksUri: 'https://localhost/jwks')];
        yield 'a jwks_uri with a fragment' => [new RegisterClientCommand('App', 'confidential', $uris, 'private_key_jwt', jwksUri: 'https://server.baander.app/jwks.json#keys')];
        yield 'an X25519 key' => [new RegisterClientCommand('App', 'confidential', $uris, 'private_key_jwt', ['keys' => [['kty' => 'OKP', 'crv' => 'X25519', 'x' => 'hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo']]])];
        yield 'keys for a secret client' => [new RegisterClientCommand('App', 'confidential', $uris, 'client_secret_post', $jwks)];
        yield 'keys without a method' => [new RegisterClientCommand('App', 'confidential', $uris, jwksUri: self::JWKS_URI)];
        yield 'a method for a public client' => [new RegisterClientCommand('App', 'public', $uris, 'private_key_jwt', $jwks)];
        yield 'an unknown method' => [new RegisterClientCommand('App', 'confidential', $uris, 'client_secret_basic')];
        yield 'the method of public clients' => [new RegisterClientCommand('App', 'confidential', $uris, 'none')];
    }

    #[DataProvider('invalidKeyRegistrations')]
    public function testInvalidKeyRegistrationsAreRejectedUnsaved(RegisterClientCommand $command): void
    {
        $this->assertInvalidRegistration($command);
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
        yield 'first-party SPA' => [Client::create('SPA', ['http://localhost'], firstParty: true, passwordClient: true), 'protected_client'];
        yield 'personal access' => [Client::createPersonalAccess('CLI', Uuid::generate()), 'protected_client'];
        yield 'public' => [Client::registerPublic('Player', ['https://player.baander.app/cb']), 'no_secret'];
        yield 'device' => [Client::registerDevice('TV'), 'no_secret'];
        yield 'key client' => [self::keyClient(), 'no_secret'];
        yield 'revoked' => [self::revoked(Client::registerConfidential('Server', ['https://server.baander.app/cb'], ClientSecret::fromString('secret'))), 'revoked'];
    }

    #[DataProvider('unrotatableClients')]
    public function testOnlyActiveSecretClientsRotate(Client $client, string $reason): void
    {
        $this->stored($client);

        $this->assertConflict($reason, fn (): mixed => (new RotateClientSecretHandler($this->repository()))(new RotateClientSecretCommand($client->getPublicId())));
    }

    public function testKeyReplacementSwitchesAnInlineKeySetToAJwksUri(): void
    {
        $client = $this->stored(self::keyClient());
        $before = $client->getUpdatedAt();

        $replaced = $this->replaceKeys(new ReplaceClientKeysCommand($client->getPublicId(), jwksUri: self::JWKS_URI));

        self::assertSame($client, $replaced);
        self::assertSame(ClientAuthMethod::PrivateKeyJwt, $client->getAuthMethod());
        self::assertSame(self::JWKS_URI, $client->getKeySource()?->jwksUri());
        self::assertNull($client->getKeySource()->jwks());
        self::assertGreaterThan($before, $client->getUpdatedAt());
    }

    public function testKeyReplacementInstallsANewInlineKeySet(): void
    {
        $client = $this->stored(self::keyClient());
        $newKey = new SignedClientAssertion('client-key-2');

        $this->replaceKeys(new ReplaceClientKeysCommand($client->getPublicId(), jwks: $newKey->jwks()));

        self::assertSame($newKey->jwks(), $client->getKeySource()?->jwks());
    }

    /** @return iterable<string, array{Client, string}> */
    public static function clientsWithoutReplaceableKeys(): iterable
    {
        yield 'first-party SPA' => [Client::create('SPA', ['http://localhost'], firstParty: true, passwordClient: true), 'protected_client'];
        yield 'secret client' => [Client::registerConfidential('Server', ['https://server.baander.app/cb'], ClientSecret::fromString('secret')), 'no_keys'];
        yield 'public' => [Client::registerPublic('Player', ['https://player.baander.app/cb']), 'no_keys'];
        yield 'revoked key client' => [self::revoked(self::keyClient()), 'revoked'];
    }

    #[DataProvider('clientsWithoutReplaceableKeys')]
    public function testOnlyActiveKeyClientsGetNewKeys(Client $client, string $reason): void
    {
        $this->stored($client);

        $this->assertConflict($reason, fn (): mixed => $this->replaceKeys(new ReplaceClientKeysCommand($client->getPublicId(), jwksUri: self::JWKS_URI)));
    }

    /** @return iterable<string, array{?array<string, mixed>, ?string}> */
    public static function invalidReplacementKeys(): iterable
    {
        yield 'neither key source' => [null, null];
        yield 'both key sources' => [(new SignedClientAssertion())->jwks(), self::JWKS_URI];
        yield 'an empty key set' => [['keys' => []], null];
        yield 'a jwks_uri on another port' => [null, 'https://server.baander.app:8443/jwks.json'];
        yield 'a jwks_uri naming an IP address' => [null, 'https://10.0.0.5/jwks.json'];
        yield 'a jwks_uri naming a single-label host' => [null, 'https://localhost/jwks'];
        yield 'a jwks_uri with a fragment' => [null, 'https://server.baander.app/jwks.json#keys'];
        yield 'an X25519 key' => [['keys' => [['kty' => 'OKP', 'crv' => 'X25519', 'x' => 'hSDwCYkwp1R0i33ctD73Wg2_Og0mOBr066SpjqqbTmo']]], null];
    }

    /** @param array<string, mixed>|null $jwks */
    #[DataProvider('invalidReplacementKeys')]
    public function testInvalidReplacementKeysAreRejectedAndTheOldKeysKept(?array $jwks, ?string $jwksUri): void
    {
        $client = $this->stored(self::keyClient());
        $keys = $client->getKeySource();

        try {
            $this->replaceKeys(new ReplaceClientKeysCommand($client->getPublicId(), $jwks, $jwksUri));
            self::fail('The keys must be rejected.');
        } catch (InvalidInputException $exception) {
            self::assertSame(['reason' => 'invalid_keys'], $exception->details);
        }

        self::assertSame($keys, $client->getKeySource());
    }

    public function testKeysOfAnUnknownClientAreNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->replaceKeys(new ReplaceClientKeysCommand(new PublicId(), jwksUri: self::JWKS_URI));
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

        $this->assertConflict('protected_client', fn (): mixed => $this->revoker()(new RevokeRegisteredClientCommand($spa->getPublicId())));

        self::assertFalse($spa->isRevoked());
        self::assertSame([], $this->revokedTokensOf);
    }

    public function testUnknownClientsAreNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->revoker()(new RevokeRegisteredClientCommand(new PublicId()));
    }

    private static function keyClient(): Client
    {
        return Client::registerConfidentialWithKeys('Signing server', ['https://server.baander.app/cb'], ClientKeySource::fromJwks((new SignedClientAssertion())->jwks()));
    }

    private static function revoked(Client $client): Client
    {
        $client->revoke();

        return $client;
    }

    private function assertInvalidRegistration(RegisterClientCommand $command): void
    {
        try {
            ($this->register())($command);
            self::fail('The registration must be rejected.');
        } catch (InvalidInputException $exception) {
            self::assertSame(['reason' => 'invalid_registration'], $exception->details);
        }

        self::assertSame([], $this->saved);
    }

    /** @param callable(): mixed $change */
    private function assertConflict(string $reason, callable $change): void
    {
        try {
            $change();
            self::fail('The change must be rejected.');
        } catch (ConflictException $exception) {
            self::assertSame(['reason' => $reason], $exception->details);
        }
    }

    private function register(): RegisterClientHandler
    {
        return new RegisterClientHandler($this->repository());
    }

    private function replaceKeys(ReplaceClientKeysCommand $command): Client
    {
        return (new ReplaceClientKeysHandler($this->repository()))($command);
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
