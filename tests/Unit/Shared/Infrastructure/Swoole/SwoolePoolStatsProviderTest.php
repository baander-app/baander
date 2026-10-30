<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\SwoolePoolStatsProvider;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;

final class SwoolePoolStatsProviderTest extends TestCase
{
    public function testIdentitySurvivesLazyPoolInsertionAndPriorityReordering(): void
    {
        $existing = $this->createStub(ServicePool::class);
        $existing->method('getAssignedCount')->willReturn(1);
        $existing->method('getFreeCount')->willReturn(2);
        $existing->method('getInstancesLimit')->willReturn(10);
        $container = new ServicePoolContainer([]);
        $container->addPool($existing);
        $provider = new SwoolePoolStatsProvider($container);
        $before = $provider->getStats();
        self::assertArrayHasKey('id', $before[0]);
        self::assertSame(spl_object_id($existing), $before[0]['id']);

        $lazy = $this->createStub(ServicePool::class);
        $container->addPool($lazy, 100);
        $after = $provider->getStats();
        self::assertCount(2, $after);
        self::assertSame(spl_object_id($lazy), $after[0]['id']);
        self::assertSame($before[0], $after[1]);
        self::assertNotSame($after[0]['id'], $after[1]['id']);
        self::assertSame(['active' => 1, 'free' => 2, 'limit' => 10], array_diff_key($after[1], ['id' => true]));
    }
}
