<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientType;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientSecret;
use App\Auth\Domain\Model\OAuth\ClientState;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testCreateWithDefaults(): void
    {
        $client = Client::create('Test App', ['http://localhost']);

        $this->assertSame('Test App', $client->getName());
        $this->assertNull($client->getSecretHash());
        $this->assertSame(['http://localhost'], $client->getRedirectUris());
        $this->assertFalse($client->isConfidential());
        $this->assertFalse($client->isRevoked());
        $this->assertFalse($client->isFirstParty());
        $this->assertFalse($client->isPersonalAccessClient());
        $this->assertFalse($client->isPasswordClient());
        $this->assertFalse($client->isDeviceClient());
    }

    public function testCreateConfidentialRequiresSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Confidential clients must have a secret');

        Client::create('Test App', [], confidential: true);
    }

    public function testCreateConfidentialStoresOnlyTheSecretDigest(): void
    {
        $client = Client::create('Test App', [], secret: ClientSecret::fromString('secret123'), confidential: true);

        $this->assertTrue($client->isConfidential());
        $this->assertSame(hash('sha256', 'secret123'), $client->getSecretHash());
        $this->assertTrue($client->authenticatesWith('secret123'));
        $this->assertFalse($client->authenticatesWith('secret124'));
        $this->assertFalse($client->authenticatesWith(''));
        $this->assertFalse($client->authenticatesWith(null));
    }

    public function testPublicClientCannotHaveASecret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Client::create('Test App', [], secret: ClientSecret::fromString('secret123'));
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Client::create('  ', []);
    }

    public function testCreatePersonalAccess(): void
    {
        $client = Client::createPersonalAccess('My Token');

        $this->assertSame('My Token', $client->getName());
        $this->assertTrue($client->isPersonalAccessClient());
        $this->assertTrue($client->isFirstParty());
    }

    public function testReconstituteRestoresRevokedState(): void
    {
        $client = Client::reconstitute(new ClientState(
            id: \App\Shared\Domain\Model\Uuid::v4(),
            publicId: \App\Shared\Domain\Model\PublicId::fromString('cli_abc123def456ghjkl'),
            name: 'Test',
            secretHash: null,
            redirectUris: [],
            personalAccessClient: false,
            passwordClient: false,
            deviceClient: false,
            confidential: false,
            firstParty: false,
            userId: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            revoked: true,
        ));

        $this->assertTrue($client->isRevoked());
    }

    public function testRevoke(): void
    {
        $client = Client::create('Test', []);
        $this->assertFalse($client->isRevoked());

        $client->revoke();

        $this->assertTrue($client->isRevoked());
    }

    public function testRevokeIdempotent(): void
    {
        $client = Client::create('Test', []);
        $client->revoke();
        $before = $client->getUpdatedAt();

        $client->revoke();

        $this->assertEquals($before, $client->getUpdatedAt());
    }

    public function testUpdateName(): void
    {
        $client = Client::create('Old', []);

        $client->updateName('New');

        $this->assertSame('New', $client->getName());
    }

    public function testUpdateNameThrowsOnEmpty(): void
    {
        $client = Client::create('Test', []);

        $this->expectException(InvalidArgumentException::class);

        $client->updateName(' ');
    }

    public function testUpdateRedirectUris(): void
    {
        $client = Client::create('Test', ['http://old']);

        $client->updateRedirectUris(['http://new']);

        $this->assertSame(['http://new'], $client->getRedirectUris());
    }

    public function testRotateSecretReplacesTheDigest(): void
    {
        $client = Client::create('Test', [], secret: ClientSecret::fromString('old'), confidential: true);

        $client->rotateSecret(ClientSecret::fromString('new'));

        $this->assertTrue($client->authenticatesWith('new'));
        $this->assertFalse($client->authenticatesWith('old'));
    }

    public function testRotateSecretRequiresAnActiveConfidentialClient(): void
    {
        $public = Client::registerPublic('Player', ['https://player.baander.app/callback']);
        try {
            $public->rotateSecret(ClientSecret::generate());
            self::fail('A public client has no secret to rotate.');
        } catch (\DomainException) {
        }

        $confidential = Client::registerConfidential('Server', ['https://server.baander.app/callback'], ClientSecret::generate());
        $confidential->revoke();
        $this->expectException(\DomainException::class);
        $confidential->rotateSecret(ClientSecret::generate());
    }

    public function testRegistrationFactoriesSetTheClientType(): void
    {
        self::assertSame(ClientType::Device, Client::registerDevice('TV')->getType());
        self::assertSame(ClientType::Public, Client::registerPublic('Player', ['app.baander.player:/callback'])->getType());
        self::assertSame(ClientType::Confidential, Client::registerConfidential('Server', ['https://server.baander.app/cb'], ClientSecret::generate())->getType());
        self::assertSame(ClientType::PersonalAccess, Client::createPersonalAccess('CLI')->getType());
        self::assertSame(ClientType::FirstParty, Client::create('SPA', ['http://localhost'], firstParty: true, passwordClient: true)->getType());
        self::assertTrue(ClientType::Device->isAdministered());
        self::assertFalse(ClientType::FirstParty->isAdministered());
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidRedirectUris(): iterable
    {
        yield 'none' => [[]];
        yield 'relative' => [['/callback']];
        yield 'fragment' => [['https://player.baander.app/callback#x']];
        yield 'plain http on a public host' => [['http://player.baander.app/callback']];
        yield 'credentials' => [['https://user:pass@player.baander.app/callback']];
        yield 'javascript scheme' => [['javascript:alert(1)']];
        yield 'private-use scheme without a domain' => [['myapp:/callback']];
        yield 'whitespace' => [['https://player.baander.app/call back']];
        yield 'too many' => [array_map(static fn (int $i): string => 'https://player.baander.app/cb' . $i, range(1, 11))];
    }

    /** @param list<string> $uris */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRedirectUris')]
    public function testRegistrationRejectsUnsafeRedirectUris(array $uris): void
    {
        $this->expectException(InvalidArgumentException::class);

        Client::registerPublic('Player', $uris);
    }

    public function testRegistrationAcceptsHttpsLoopbackAndReverseDomainSchemes(): void
    {
        $uris = ['https://player.baander.app/callback', 'http://127.0.0.1/callback', 'http://localhost:8080/cb', 'app.baander.player:/callback'];

        self::assertSame($uris, Client::registerPublic('Player', $uris)->getRedirectUris());
    }

    public function testClientSecretIsHighEntropyAndMatchesInConstantTime(): void
    {
        $secret = ClientSecret::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $secret->toString());
        self::assertNotSame($secret->toString(), ClientSecret::generate()->toString());
        self::assertSame(hash('sha256', $secret->toString()), $secret->hash());
        self::assertTrue(ClientSecret::matches($secret->hash(), $secret->toString()));
        self::assertFalse(ClientSecret::matches($secret->hash(), $secret->hash()));
    }

    public function testGettersReturnExpectedTypes(): void
    {
        $client = Client::create('Test', []);

        $this->assertInstanceOf(\App\Shared\Domain\Model\Uuid::class, $client->getId());
        $this->assertInstanceOf(\App\Shared\Domain\Model\PublicId::class, $client->getPublicId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $client->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $client->getUpdatedAt());
    }

    public function testCreateWithUserId(): void
    {
        $userId = Uuid::v4();
        $client = Client::create('Test', [], userId: $userId);

        $this->assertNotNull($client->getUserId());
        $this->assertTrue($client->getUserId()->equals($userId));
    }

    public function testCreateWithoutUserIdDefaultsToNull(): void
    {
        $client = Client::create('Test', []);

        $this->assertNull($client->getUserId());
    }

    public function testCreatePersonalAccessWithUserId(): void
    {
        $userId = Uuid::v4();
        $client = Client::createPersonalAccess('My Token', $userId);

        $this->assertNotNull($client->getUserId());
        $this->assertTrue($client->getUserId()->equals($userId));
        $this->assertTrue($client->isPersonalAccessClient());
    }

    public function testIsOwnedByReturnsTrueForMatchingUserId(): void
    {
        $userId = Uuid::v4();
        $client = Client::create('Test', [], userId: $userId);

        $this->assertTrue($client->isOwnedBy($userId));
    }

    public function testIsOwnedByReturnsFalseForDifferentUserId(): void
    {
        $userId = Uuid::v4();
        $otherUserId = Uuid::v4();
        $client = Client::create('Test', [], userId: $userId);

        $this->assertFalse($client->isOwnedBy($otherUserId));
    }

    public function testIsOwnedByReturnsFalseWhenUserIdIsNull(): void
    {
        $client = Client::create('Test', []);
        $userId = Uuid::v4();

        $this->assertFalse($client->isOwnedBy($userId));
    }

    public function testReconstituteWithUserId(): void
    {
        $userId = Uuid::v4();
        $client = Client::reconstitute(new ClientState(
            id: Uuid::v4(),
            publicId: \App\Shared\Domain\Model\PublicId::fromString('cli_abc123def456ghjkl'),
            name: 'Test',
            secretHash: null,
            redirectUris: [],
            personalAccessClient: false,
            passwordClient: false,
            deviceClient: false,
            confidential: false,
            firstParty: false,
            userId: $userId,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            revoked: false,
        ));

        $this->assertNotNull($client->getUserId());
        $this->assertTrue($client->getUserId()->equals($userId));
        $this->assertTrue($client->isOwnedBy($userId));
    }
}
