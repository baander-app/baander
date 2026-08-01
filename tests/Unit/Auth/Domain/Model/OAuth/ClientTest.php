<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\OAuth\Client;
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
        $this->assertNull($client->getSecret());
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

    public function testCreateConfidentialWithSecret(): void
    {
        $client = Client::create('Test App', [], secret: 'secret123', confidential: true);

        $this->assertTrue($client->isConfidential());
        $this->assertSame('secret123', $client->getSecret());
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
            secret: null,
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

    public function testUpdateSecret(): void
    {
        $client = Client::create('Test', [], secret: 'old', confidential: true);

        $client->updateSecret('new');

        $this->assertSame('new', $client->getSecret());
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
            secret: null,
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
