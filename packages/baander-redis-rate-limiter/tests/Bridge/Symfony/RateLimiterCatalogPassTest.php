<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Tests\Bridge\Symfony;

use Baander\RedisRateLimiter\Bridge\Symfony\BaanderRedisRateLimiterBundle;
use Baander\RedisRateLimiter\Administration\RateLimiterAdministrationInterface;
use Baander\RedisRateLimiter\Administration\RedisRateLimiterAdministration;
use Baander\RedisRateLimiter\Limiter\RedisRateLimiterFactory;
use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;
use Baander\RedisRateLimiter\Bridge\Symfony\RateLimiterCatalogPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

final class RateLimiterCatalogPassTest extends TestCase
{
    public function testBundleRegistersTheCatalogCompilerPass(): void
    {
        $container = new ContainerBuilder();
        new BaanderRedisRateLimiterBundle()->build($container);

        self::assertCount(1, array_filter(
            $container->getCompilerPassConfig()->getBeforeOptimizationPasses(),
            static fn(object $pass): bool => $pass instanceof RateLimiterCatalogPass,
        ));
    }

    public function testBundleRegistersAdministrationAndItsConfiguredCatalog(): void
    {
        $container = new ContainerBuilder();
        new BaanderRedisRateLimiterBundle()->build($container);
        $container->setParameter(RateLimiterCatalogPass::DESCRIPTIONS_PARAMETER, ['api' => 'API requests']);
        $this->addLimiter($container, 'api', ['policy' => 'fixed_window', 'limit' => 10, 'interval' => '60 seconds']);

        self::assertTrue($container->hasDefinition(RedisRateLimiterAdministration::class));
        self::assertSame(RedisRateLimiterAdministration::class, (string) $container->getAlias(RateLimiterAdministrationInterface::class));
        $administration = $container->getDefinition(RedisRateLimiterAdministration::class);
        self::assertSame([], $administration->getArgument('$limiters'));
        self::assertSame(RedisRateLimiterStore::class, (string) $administration->getArgument('$store'));

        foreach ($container->getCompilerPassConfig()->getBeforeOptimizationPasses() as $pass) {
            if ($pass instanceof RateLimiterCatalogPass) {
                $pass->process($container);
            }
        }

        self::assertSame([
            'api' => ['policy' => 'fixed_window', 'limit' => 10, 'interval' => '60 seconds', 'description' => 'API requests', 'storageNamespace' => RedisRateLimiterStore::namespace('api')],
        ], $administration->getArgument('$limiters'));
    }

    public function testCatalogAndConsumersShareTheAtomicStoreAndEffectiveConfiguration(): void
    {
        $container = $this->container();
        $container->setParameter('login.max_attempts', 20);
        $this->addLimiter($container, 'login', ['policy' => 'fixed_window', 'limit' => '%login.max_attempts%', 'interval' => '300 seconds']);
        $this->addLimiter($container, 'api', ['policy' => 'sliding_window', 'limit' => 360, 'interval' => '60 seconds']);
        $this->addLimiter($container, 'upstream', ['policy' => 'token_bucket', 'limit' => 1, 'rate' => ['interval' => '1 second', 'amount' => 1]]);

        (new RateLimiterCatalogPass())->process($container);

        $catalog = $container->getDefinition(RedisRateLimiterAdministration::class)->getArgument('$limiters');
        self::assertSame([
            'login' => ['policy' => 'fixed_window', 'limit' => 20, 'interval' => '300 seconds', 'description' => 'Login attempts', 'storageNamespace' => RedisRateLimiterStore::namespace('login')],
            'api' => ['policy' => 'sliding_window', 'limit' => 360, 'interval' => '60 seconds', 'description' => null, 'storageNamespace' => RedisRateLimiterStore::namespace('api')],
            'upstream' => ['policy' => 'token_bucket', 'limit' => 1, 'interval' => '1 second', 'description' => null, 'storageNamespace' => RedisRateLimiterStore::namespace('upstream')],
        ], $catalog);
        foreach (['login', 'api', 'upstream'] as $name) {
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
        $this->expectExceptionMessage('must use a fixed_window, sliding_window or token_bucket policy');
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
