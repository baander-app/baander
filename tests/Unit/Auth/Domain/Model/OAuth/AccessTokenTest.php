<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\AccessTokenState;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Shared\Domain\Model\Email;
use DateInterval;
use PHPUnit\Framework\TestCase;

final class AccessTokenTest extends TestCase
{
    private Client $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = Client::create('Test', ['http://localhost']);
        $this->user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
    }

    public function testIssueWithMinimalParams(): void
    {
        $token = AccessToken::issue($this->client);

        $this->assertInstanceOf(\App\Shared\Domain\Model\Uuid::class, $token->getId());
        $this->assertNull($token->getUser());
        $this->assertNull($token->getName());
        $this->assertSame($this->client, $token->getClient());
        $this->assertFalse($token->isRevoked());
        $this->assertNull($token->getExpiresAt());
        $this->assertNull($token->getLastRefreshedAt());
        $this->assertFalse($token->isExpired());
    }

    public function testIssueWithAllParams(): void
    {
        $scopes = [new Scope('profile'), new Scope('admin')];
        $token = AccessToken::issue(
            client: $this->client,
            user: $this->user,
            scopes: $scopes,
            name: 'My Token',
            ttl: new DateInterval('PT1H'),
            chainId: \App\Auth\Domain\Model\OAuth\ValueObject\ChainId::generate(),
        );

        $this->assertSame($this->user, $token->getUser());
        $this->assertSame('My Token', $token->getName());
        $this->assertCount(2, $token->getScopes());
        $this->assertNotNull($token->getExpiresAt());
        $this->assertInstanceOf(\App\Auth\Domain\Model\OAuth\ValueObject\ChainId::class, $token->getChainId());
    }

    public function testIssueWithoutTtlHasNoExpiry(): void
    {
        $token = AccessToken::issue($this->client);

        $this->assertNull($token->getExpiresAt());
        $this->assertFalse($token->isExpired());
    }

    public function testIssueWithPastTtlIsExpired(): void
    {
        $token = AccessToken::issue($this->client, ttl: new DateInterval('PT0S'));

        $this->assertTrue($token->isExpired());
    }

    public function testRevoke(): void
    {
        $token = AccessToken::issue($this->client);

        $token->revoke();

        $this->assertTrue($token->isRevoked());
    }

    public function testRevokeIdempotent(): void
    {
        $token = AccessToken::issue($this->client);
        $token->revoke();
        $before = $token->getUpdatedAt();

        $token->revoke();

        $this->assertEquals($before, $token->getUpdatedAt());
    }

    public function testMarkRefreshed(): void
    {
        $token = AccessToken::issue($this->client);

        $token->markRefreshed();

        $this->assertNotNull($token->getLastRefreshedAt());
    }

    public function testGetScopeIdentifiers(): void
    {
        $token = AccessToken::issue($this->client, scopes: [new Scope('access-api')]);

        $this->assertSame(['access-api'], $token->getScopeIdentifiers());
    }

    public function testReconstitute(): void
    {
        $chainId = \App\Auth\Domain\Model\OAuth\ValueObject\ChainId::generate();
        $now = new \DateTimeImmutable();
        $expiresAt = (new \DateTimeImmutable())->add(new DateInterval('PT1H'));

        $token = AccessToken::reconstitute(new AccessTokenState(
            id: \App\Shared\Domain\Model\Uuid::v4(),
            tokenId: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            user: $this->user,
            client: $this->client,
            name: 'Token',
            scopes: [new Scope('admin')],
            chainId: $chainId,
            expiresAt: $expiresAt,
            lastRefreshedAt: null,
            createdAt: $now,
            updatedAt: $now,
            revoked: false,
        ));

        $this->assertSame($chainId, $token->getChainId());
        $this->assertSame('Token', $token->getName());
        $this->assertSame($expiresAt, $token->getExpiresAt());
    }
}
