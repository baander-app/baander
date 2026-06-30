<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Domain\Model;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\RefreshTokenState;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use DateInterval;
use PHPUnit\Framework\TestCase;

final class RefreshTokenTest extends TestCase
{
    private AccessToken $accessToken;
    private ChainId $chainId;

    protected function setUp(): void
    {
        $client = Client::create('Test', []);
        $this->chainId = ChainId::generate();
        $this->accessToken = AccessToken::issue($client, chainId: $this->chainId);
    }

    public function testIssueWithMinimalParams(): void
    {
        $token = RefreshToken::issue($this->accessToken, $this->chainId);

        $this->assertNull($token->getPreviousRefreshToken());
        $this->assertNull($token->getExpiresAt());
        $this->assertNull($token->getUsedAt());
        $this->assertFalse($token->isRevoked());
        $this->assertFalse($token->hasBeenUsed());
        $this->assertFalse($token->isExpired());
    }

    public function testIssueWithTtl(): void
    {
        $token = RefreshToken::issue($this->accessToken, $this->chainId, new DateInterval('PT1H'));

        $this->assertNotNull($token->getExpiresAt());
        $this->assertFalse($token->isExpired());
    }

    public function testIssueWithPreviousToken(): void
    {
        $first = RefreshToken::issue($this->accessToken, $this->chainId);
        $second = RefreshToken::issue($this->accessToken, $this->chainId, previousRefreshToken: $first);

        $this->assertSame($first, $second->getPreviousRefreshToken());
    }

    public function testIssueWithExpiredTtl(): void
    {
        $token = RefreshToken::issue($this->accessToken, $this->chainId, new DateInterval('PT0S'));

        $this->assertTrue($token->isExpired());
    }

    public function testRevoke(): void
    {
        $token = RefreshToken::issue($this->accessToken, $this->chainId);

        $token->revoke();

        $this->assertTrue($token->isRevoked());
    }

    public function testRevokeIdempotent(): void
    {
        $token = RefreshToken::issue($this->accessToken, $this->chainId);
        $token->revoke();
        $before = $token->getUpdatedAt();

        $token->revoke();

        $this->assertEquals($before, $token->getUpdatedAt());
    }

    public function testMarkUsed(): void
    {
        $token = RefreshToken::issue($this->accessToken, $this->chainId);

        $token->markUsed();

        $this->assertTrue($token->hasBeenUsed());
        $this->assertNotNull($token->getUsedAt());
    }

    public function testReconstitute(): void
    {
        $now = new \DateTimeImmutable();
        $usedAt = new \DateTimeImmutable('-1 minute');

        $token = RefreshToken::reconstitute(new RefreshTokenState(
            id: \App\Shared\Domain\Model\Uuid::v4(),
            tokenId: \App\Auth\Domain\Model\OAuth\TokenId::generate(),
            accessToken: $this->accessToken,
            chainId: $this->chainId,
            previousRefreshToken: null,
            expiresAt: null,
            usedAt: $usedAt,
            createdAt: $now,
            updatedAt: $now,
            revoked: true,
        ));

        $this->assertTrue($token->isRevoked());
        $this->assertTrue($token->hasBeenUsed());
    }

    public function testGetChainId(): void
    {
        $token = RefreshToken::issue($this->accessToken, $this->chainId);

        $this->assertSame($this->chainId, $token->getChainId());
    }

    public function testGetAccessToken(): void
    {
        $token = RefreshToken::issue($this->accessToken, $this->chainId);

        $this->assertSame($this->accessToken, $token->getAccessToken());
    }
}
