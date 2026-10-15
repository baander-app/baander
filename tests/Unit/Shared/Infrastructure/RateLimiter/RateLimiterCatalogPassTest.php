<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterAdministration;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterFactory;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterStore;
use App\Shared\Infrastructure\RateLimiter\RateLimiterCatalogPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

final class RateLimiterCatalogPassTest extends TestCase
{
    public function testCatalogAndConsumersShareTheAtomicStoreAndEffectiveConfiguration(): void
    {
        $container = $this->container();
        $container->setParameter('login.max_attempts', 20);
        $this->addLimiter($container, 'login', ['policy' => 'fixed_window', 'limit' => '%login.max_attempts%', 'interval' => '300 seconds']);
        $this->addLimiter($container, 'api', ['policy' => 'sliding_window', 'limit' => 360, 'interval' => '60 seconds']);

        (new RateLimiterCatalogPass())->process($container);

        $catalog = $container->getDefinition(RedisRateLimiterAdministration::class)->getArgument('$limiters');
        self::assertSame([
            'login' => ['policy' => 'fixed_window', 'limit' => 20, 'interval' => '300 seconds', 'description' => 'Login attempts', 'storageNamespace' => RedisRateLimiterStore::namespace('login')],
            'api' => ['policy' => 'sliding_window', 'limit' => 360, 'interval' => '60 seconds', 'description' => null, 'storageNamespace' => RedisRateLimiterStore::namespace('api')],
        ], $catalog);
        foreach (['login', 'api'] as $name) {
            $factory = $container->getDefinition('limiter.' . $name);
            self::assertSame(RedisRateLimiterFactory::class, $factory->getClass());
            self::assertSame(RedisRateLimiterStore::class, (string) $factory->getArgument(1));
            self::assertSame([['name' => $name]], $factory->getTag('rate_limiter'));
        }
        self::assertSame(20, $container->getDefinition('limiter.login')->getArgument(0)['limit']);
    }

    public function testLimitersRemainAtomicWithoutTheAdministrationService(): void
    {
        $container = new ContainerBuilder();
        $this->addLimiter($container, 'api', ['policy' => 'fixed_window', 'limit' => 10, 'interval' => '60 seconds']);
        (new RateLimiterCatalogPass())->process($container);
        self::assertSame(RedisRateLimiterFactory::class, $container->getDefinition('limiter.api')->getClass());
    }

    public function testRejectsUnsupportedPoliciesAtCompilation(): void
    {
        $container = $this->container();
        $this->addLimiter($container, 'login', ['policy' => 'no_limit']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must use a fixed_window or sliding_window policy');
        (new RateLimiterCatalogPass())->process($container);
    }

    public function testRejectsAnIdentityThatWouldClearDifferentState(): void
    {
        $container = $this->container();
        $this->addLimiter($container, 'login', ['id' => 'other', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '60 seconds']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unique matching configuration id');
        (new RateLimiterCatalogPass())->process($container);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(RedisRateLimiterAdministration::class, RedisRateLimiterAdministration::class);
        $container->setParameter(RateLimiterCatalogPass::DESCRIPTIONS_PARAMETER, ['login' => 'Login attempts']);

        return $container;
    }

    /** @param array<string, mixed> $config */
    private function addLimiter(ContainerBuilder $container, string $name, array $config): void
    {
        $limiter = new ChildDefinition('limiter');
        $limiter->replaceArgument(0, $config + ['id' => $name]);
        $limiter->replaceArgument(1, new Reference('limiter.storage.' . $name));
        $limiter->addTag('rate_limiter', ['name' => $name]);
        $container->setDefinition('limiter.' . $name, $limiter);
    }
}
