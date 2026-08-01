<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\AuthCodeState;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Email;
use DateInterval;
use PHPUnit\Framework\TestCase;

final class AuthCodeTest extends TestCase
{
    private Client $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = Client::create('Test', []);
        $this->user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
    }

    public function testCreateWithMinimalParams(): void
    {
        $code = AuthCode::create($this->user, $this->client);

        $this->assertNull($code->getExpiresAt());
        $this->assertFalse($code->isExpired());
        $this->assertFalse($code->isRevoked());
        $this->assertSame($this->user, $code->getUser());
        $this->assertSame($this->client, $code->getClient());
        $this->assertEmpty($code->getScopes());
    }

    public function testCreateWithTtl(): void
    {
        $code = AuthCode::create($this->user, $this->client, [new Scope('profile')], new DateInterval('PT10M'));

        $this->assertNotNull($code->getExpiresAt());
        $this->assertFalse($code->isExpired());
        $this->assertCount(1, $code->getScopes());
        $this->assertSame(['profile'], $code->getScopeIdentifiers());
    }

    public function testCreateWithExpiredTtl(): void
    {
        $code = AuthCode::create($this->user, $this->client, ttl: new DateInterval('PT0S'));

        $this->assertTrue($code->isExpired());
    }

    public function testRevoke(): void
    {
        $code = AuthCode::create($this->user, $this->client);

        $code->revoke();

        $this->assertTrue($code->isRevoked());
    }

    public function testRevokeIdempotent(): void
    {
        $code = AuthCode::create($this->user, $this->client);
        $code->revoke();
        $before = $code->getUpdatedAt();

        $code->revoke();

        $this->assertEquals($before, $code->getUpdatedAt());
    }

    public function testReconstitute(): void
    {
        $now = new \DateTimeImmutable();

        $code = AuthCode::reconstitute(new AuthCodeState(
            id: \App\Shared\Domain\Model\Uuid::v4(),
            codeId: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            user: $this->user,
            client: $this->client,
            scopes: [],
            expiresAt: null,
            createdAt: $now,
            updatedAt: $now,
            revoked: true,
        ));

        $this->assertTrue($code->isRevoked());
    }
}
