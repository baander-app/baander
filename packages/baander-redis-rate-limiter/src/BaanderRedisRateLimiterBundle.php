<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class BaanderRedisRateLimiterBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new RateLimiterCatalogPass());
    }
}
