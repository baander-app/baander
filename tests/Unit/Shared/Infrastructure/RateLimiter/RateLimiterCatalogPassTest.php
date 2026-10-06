<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use App\Shared\Infrastructure\RateLimiter\CachePoolRateLimiterAdministration;
use App\Shared\Infrastructure\RateLimiter\RateLimiterCatalogPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

final class RateLimiterCatalogPassTest extends TestCase
{
    public function testBuildsTheCatalogFromTheRegisteredLimiters(): void
    {
        $container = $this->container();
        $container->setParameter('login.max_attempts', 20);
        $this->addLimiter($container, 'login', 'cache.rate_limiter.login', [
            'policy' => 'fixed_window',
            'limit' => '%login.max_attempts%',
            'interval' => '300 seconds',
        ]);
        $this->addLimiter($container, 'api', 'cache.rate_limiter.api', [
            'policy' => 'sliding_window',
            'limit' => 360,
            'interval' => '60 seconds',
        ]);

        (new RateLimiterCatalogPass())->process($container);

        $administration = $container->getDefinition(CachePoolRateLimiterAdministration::class);
        self::assertSame([
            'login' => [
                'policy' => 'fixed_window',
                'limit' => 20,
                'interval' => '300 seconds',
                'description' => 'Login attempts',
                'cachePool' => 'cache.rate_limiter.login',
            ],
            'api' => [
                'policy' => 'sliding_window',
                'limit' => 360,
                'interval' => '60 seconds',
                'description' => null,
                'cachePool' => 'cache.rate_limiter.api',
            ],
        ], $administration->getArgument('$limiters'));
        self::assertInstanceOf(Reference::class, $administration->getArgument('$pools'));
    }

    public function testRejectsLimitersSharingACachePool(): void
    {
        $container = $this->container();
        $config = ['policy' => 'fixed_window', 'limit' => 10, 'interval' => '60 seconds'];
        $this->addLimiter($container, 'login', 'cache.rate_limiter', $config);
        $this->addLimiter($container, 'register', 'cache.rate_limiter', $config);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Rate limiters "login" and "register" share cache pool "cache.rate_limiter"');

        (new RateLimiterCatalogPass())->process($container);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(CachePoolRateLimiterAdministration::class, CachePoolRateLimiterAdministration::class);
        $container->setParameter(RateLimiterCatalogPass::DESCRIPTIONS_PARAMETER, ['login' => 'Login attempts']);

        return $container;
    }

    /** @param array<string, mixed> $config */
    private function addLimiter(ContainerBuilder $container, string $name, string $pool, array $config): void
    {
        if (!$container->hasDefinition($pool)) {
            $container->register($pool, ArrayAdapter::class);
        }
        $container->register('limiter.storage.' . $name, CacheStorage::class)->addArgument(new Reference($pool));

        $limiter = new ChildDefinition('limiter');
        $limiter->replaceArgument(0, $config + ['id' => $name]);
        $limiter->replaceArgument(1, new Reference('limiter.storage.' . $name));
        $limiter->addTag('rate_limiter', ['name' => $name]);
        $container->setDefinition('limiter.' . $name, $limiter);
    }
}
