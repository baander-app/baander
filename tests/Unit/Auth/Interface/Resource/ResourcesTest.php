<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Resource;

use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\User;
use App\Auth\Interface\Resource\ClientResource;
use App\Auth\Interface\Resource\TokenResource;
use App\Auth\Interface\Resource\UserResource;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\TestCase;

final class ResourcesTest extends TestCase
{
    public function testUserResourceFrom(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $result = UserResource::from($user);

        $this->assertSame($user->getId()->toString(), $result['uuid']);
        $this->assertSame($user->getPublicId()->toString(), $result['publicId']);
        $this->assertSame('Alice', $result['name']);
        $this->assertSame('test@example.com', $result['email']);
        $this->assertNull($result['emailVerifiedAt']);
        $this->assertArrayHasKey('createdAt', $result);
    }

    public function testUserResourceFromDomainBackwardCompat(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $result = UserResource::fromDomain($user);

        $this->assertSame('Alice', $result['name']);
    }

    public function testTokenResourceFrom(): void
    {
        $dto = new TokenResponseDTO(accessToken: 'tok-123', refreshToken: 'ref-456', scopes: ['admin']);
        $result = TokenResource::from($dto);

        $this->assertSame('tok-123', $result['accessToken']);
        $this->assertSame('DPoP', $result['tokenType']);
        $this->assertSame(3600, $result['expiresIn']);
        $this->assertSame('ref-456', $result['refreshToken']);
    }

    public function testTokenResourceFromDtoBackwardCompat(): void
    {
        $dto = new TokenResponseDTO(accessToken: 'tok');
        $result = TokenResource::fromDto($dto);

        $this->assertSame('tok', $result['accessToken']);
    }

    public function testClientResourceFrom(): void
    {
        $client = Client::create('Test App', ['http://localhost'], secret: 'secret', confidential: true);
        $result = ClientResource::from($client);

        $this->assertSame($client->getId()->toString(), $result['uuid']);
        $this->assertSame($client->getPublicId()->toString(), $result['publicId']);
        $this->assertSame('Test App', $result['name']);
        $this->assertSame('secret', $result['secret']);
        $this->assertTrue($result['confidential']);
        $this->assertFalse($result['personalAccessClient']);
        $this->assertArrayHasKey('createdAt', $result);
    }
}
