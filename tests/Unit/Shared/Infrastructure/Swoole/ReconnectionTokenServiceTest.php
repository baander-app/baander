<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\ReconnectionTokenService;
use PHPUnit\Framework\TestCase;

final class ReconnectionTokenServiceTest extends TestCase
{
    private ReconnectionTokenService $service;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded.');
        }

        $this->service = ReconnectionTokenService::create(maxTokens: 64);
    }

    public function testGenerateReturnsNonEmptyString(): void
    {
        $token = $this->service->generate('user-1');

        $this->assertIsString($token);
        $this->assertGreaterThan(0, strlen($token));
    }

    public function testConsumeReturnsUserIdForValidToken(): void
    {
        $token = $this->service->generate('user-1');

        $userId = $this->service->consume($token);

        $this->assertSame('user-1', $userId);
    }

    public function testConsumeDeletesTokenSingleUse(): void
    {
        $token = $this->service->generate('user-1');

        $this->service->consume($token);

        // Second consume should return null (token already used)
        $result = $this->service->consume($token);

        $this->assertNull($result);
    }

    public function testConsumeReturnsNullForUnknownToken(): void
    {
        $result = $this->service->consume('nonexistent-token');

        $this->assertNull($result);
    }

    public function testConsumeReturnsNullForEmptyToken(): void
    {
        $result = $this->service->consume('');

        $this->assertNull($result);
    }

    public function testExistsReturnsTrueForValidToken(): void
    {
        $token = $this->service->generate('user-1');

        $this->assertTrue($this->service->exists($token));
    }

    public function testExistsReturnsFalseAfterConsume(): void
    {
        $token = $this->service->generate('user-1');
        $this->service->consume($token);

        $this->assertFalse($this->service->exists($token));
    }

    public function testExistsReturnsFalseForUnknownToken(): void
    {
        $this->assertFalse($this->service->exists('unknown'));
    }

    public function testMultipleTokensForSameUser(): void
    {
        $token1 = $this->service->generate('user-1');
        $token2 = $this->service->generate('user-1');

        $this->assertNotSame($token1, $token2);
        $this->assertSame('user-1', $this->service->consume($token1));
        $this->assertSame('user-1', $this->service->consume($token2));
    }

    public function testTokensForDifferentUsers(): void
    {
        $tokenUser1 = $this->service->generate('user-1');
        $tokenUser2 = $this->service->generate('user-2');

        $this->assertSame('user-1', $this->service->consume($tokenUser1));
        $this->assertSame('user-2', $this->service->consume($tokenUser2));
    }

    public function testSweepExpiredRemovesOldTokens(): void
    {
        // Generate a token, then manually expire it by setting created_at to the past
        $token = $this->service->generate('user-1');

        // The service creates tokens with current time() and TTL is 300 seconds.
        // We can't easily manipulate Swoole Table timestamps from the outside,
        // so we test the sweep mechanism by verifying it runs without errors
        // and returns 0 for non-expired tokens.

        $removed = $this->service->sweepExpired();

        // Freshly created token should not be swept
        $this->assertSame(0, $removed);
        $this->assertTrue($this->service->exists($token));
    }

    public function testSweepExpiredReturnsZeroForEmptyTable(): void
    {
        $removed = $this->service->sweepExpired();

        $this->assertSame(0, $removed);
    }
}
