<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Cache;

use App\Shared\Infrastructure\Cache\CacheTags;
use PHPUnit\Framework\TestCase;

final class CacheTagsTest extends TestCase
{
    public function testOauthTokenConstantIsNonEmptyString(): void
    {
        $this->assertNotEmpty(CacheTags::OAUTH_TOKEN);
        $this->assertIsString(CacheTags::OAUTH_TOKEN);
    }

    public function testOauthTokenFollowsContextEntityConvention(): void
    {
        $this->assertSame('oauth_token', CacheTags::OAUTH_TOKEN);
    }

    public function testOauthTokenReturnsTagWithId(): void
    {
        $tag = CacheTags::oauthToken('some-token-id');

        $this->assertSame('oauth_token_some-token-id', $tag);
        $this->assertStringStartsWith('oauth_token_', $tag);
    }

    public function testOauthTokenWithEmptyId(): void
    {
        $tag = CacheTags::oauthToken('');

        $this->assertSame('oauth_token_', $tag);
    }
}
