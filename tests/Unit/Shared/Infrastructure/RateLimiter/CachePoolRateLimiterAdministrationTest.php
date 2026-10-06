<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use App\Shared\Application\Exception\RateLimiterClearFailedException;
use App\Shared\Infrastructure\RateLimiter\CachePoolRateLimiterAdministration;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class CachePoolRateLimiterAdministrationTest extends TestCase
{
    public function testClearAllClearsEveryPoolAndReportsTheOnesThatFailed(): void
    {
        $healthy = new ArrayAdapter();
        $healthy->save($healthy->getItem('state')->set('exhausted'));
        $broken = $this->createStub(CacheItemPoolInterface::class);
        $broken->method('clear')->willReturn(false);

        $administration = new CachePoolRateLimiterAdministration(
            [
                'broken' => $this->limiter('cache.rate_limiter.broken'),
                'healthy' => $this->limiter('cache.rate_limiter.healthy'),
            ],
            new ServiceLocator(['broken' => static fn () => $broken, 'healthy' => static fn () => $healthy]),
        );

        try {
            $administration->clearAll();
            self::fail('Expected the failed pool to be reported.');
        } catch (RateLimiterClearFailedException $e) {
            self::assertStringContainsString('broken', $e->getMessage());
            self::assertStringNotContainsString('healthy', $e->getMessage());
        }
        self::assertFalse($healthy->hasItem('state'));
    }

    /** @return array{policy: string, limit: int, interval: string, description: null, cachePool: string} */
    private function limiter(string $pool): array
    {
        return ['policy' => 'fixed_window', 'limit' => 10, 'interval' => '60 seconds', 'description' => null, 'cachePool' => $pool];
    }
}
