<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Bridge\Symfony;

use Baander\RedisRateLimiter\Administration\RateLimiterAdministrationInterface;
use Baander\RedisRateLimiter\Administration\RedisRateLimiterAdministration;
use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class BaanderRedisRateLimiterBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->register(RedisRateLimiterAdministration::class)
            ->setArgument('$limiters', [])
            ->setArgument('$store', new Reference(RedisRateLimiterStore::class));
        $container->setAlias(RateLimiterAdministrationInterface::class, RedisRateLimiterAdministration::class);
        $container->addCompilerPass(new RateLimiterCatalogPass());
    }
}
