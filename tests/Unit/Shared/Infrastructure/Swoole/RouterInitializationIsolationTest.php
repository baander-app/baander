<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use SwooleBundle\SwooleBundle\Component\Locking\Channel\ChannelMutex;
use Swoole\Coroutine;
use Symfony\Component\Config\ConfigCacheFactory;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Router\LockingConfigCacheFactory;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Router\LockingLoader;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\Config\Loader\LoaderInterface;

final class RouterInitializationIsolationTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConcurrentColdRoutersLoadAndBuildEachSharedResourceOnce(): void
    {
        self::assertTrue(extension_loaded('swoole'), 'This proof requires the native coroutine runtime.');
        $decorated = $this->createMock(LoaderInterface::class);
        $activeLoads = 0;
        $maximumLoads = 0;
        $decorated->expects(self::exactly(2))->method('load')->willReturnCallback(
            static function ($resource) use (&$activeLoads, &$maximumLoads): string {
                $maximumLoads = max($maximumLoads, ++$activeLoads);
                Coroutine::sleep(.01);
                --$activeLoads;
                return $resource;
            },
        );
        $loader = new LockingLoader($decorated, new ChannelMutex(acquireTimeout: .5));
        $results = [];
        $initializations = 0;
        $path = sys_get_temp_dir() . '/baander-router-' . bin2hex(random_bytes(8));
        $cacheFactory = new LockingConfigCacheFactory(new ConfigCacheFactory(false), new ChannelMutex(acquireTimeout: .5));

        try {
            \Swoole\Coroutine\run(static function () use ($loader, $cacheFactory, $path, &$initializations, &$results): void {
                for ($index = 0; $index < 6; ++$index) {
                    Coroutine::create(static function () use ($loader, $cacheFactory, $path, $index, &$initializations, &$results): void {
                        $results[] = $loader->load($index % 2 === 0 ? 'api' : 'web', 'attribute');
                        $cacheFactory->cache($path, static function (ConfigCacheInterface $cache) use (&$initializations): void {
                            ++$initializations;
                            Coroutine::sleep(.01);
                            $cache->write('<?php return [];');
                        });
                    });
                }
            });
            sort($results);
            self::assertSame(['api', 'api', 'api', 'web', 'web', 'web'], $results);
            self::assertSame(1, $maximumLoads);
            self::assertSame(1, $initializations);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @phpstan-impure */
    private function loadRoutes(LockingLoader $loader, string $resource, ?string $type): mixed
    {
        return $loader->load($resource, $type);
    }

    /** @phpstan-impure */
    private function loadCache(LockingConfigCacheFactory $factory, string $path): ConfigCacheInterface
    {
        return $factory->cache($path, static function (): void {});
    }

    public function testLoaderCachesByResourceAndTypeWhileHoldingTheMutex(): void
    {
        $mutex = new RouterInitializationMutex();
        $decorated = $this->createMock(LoaderInterface::class);
        $decorated->expects(self::exactly(2))->method('load')->willReturnCallback(static function ($resource, $type) use ($mutex) {
            self::assertTrue($mutex->isAcquired());
            return $type;
        });
        $loader = new LockingLoader($decorated, $mutex);
        self::assertSame('attribute', $this->loadRoutes($loader, 'routes', 'attribute'));
        self::assertSame('attribute', $this->loadRoutes($loader, 'routes', 'attribute'));
        self::assertSame('yaml', $loader->load('routes', 'yaml'));
        self::assertFalse($mutex->isAcquired());
    }

    public function testNullResultsAreCachedAndResourceTypePairsCannotCollide(): void
    {
        $decorated = $this->createMock(LoaderInterface::class);
        $decorated->expects(self::exactly(2))->method('load')->willReturn(null);
        $loader = new LockingLoader($decorated, new RouterInitializationMutex());
        self::assertNull($loader->load('routes:api', 'yaml'));
        self::assertNull($loader->load('routes:api', 'yaml'));
        self::assertNull($loader->load('routes', 'api:yaml'));
    }

    public function testLoaderFailureReleasesMutexAndAllowsRetry(): void
    {
        $mutex = new RouterInitializationMutex();
        $decorated = $this->createMock(LoaderInterface::class);
        $attempts = 0;
        $decorated->expects(self::exactly(2))->method('load')->willReturnCallback(static function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new \RuntimeException('retry');
            }
            return 'loaded';
        });
        $loader = new LockingLoader($decorated, $mutex);
        try {
            $loader->load('routes');
            self::fail('Expected loading to fail.');
        } catch (\RuntimeException) {
            self::assertFalse($mutex->isAcquired());
        }
        self::assertSame('loaded', $loader->load('routes'));
    }

    public function testCacheSerializesFirstInitializationButDelegatesFreshnessOnReuse(): void
    {
        $mutex = new RouterInitializationMutex();
        $decorated = $this->createMock(ConfigCacheFactoryInterface::class);
        $cache = $this->createStub(ConfigCacheInterface::class);
        $calls = 0;
        $decorated->expects(self::exactly(2))->method('cache')->willReturnCallback(static function () use ($mutex, $cache, &$calls) {
            self::assertSame(++$calls === 1, $mutex->isAcquired());
            return $cache;
        });
        $factory = new LockingConfigCacheFactory($decorated, $mutex);
        $path = sys_get_temp_dir() . '/baander-router-' . bin2hex(random_bytes(8));
        self::assertSame($cache, $this->loadCache($factory, $path));
        self::assertSame($cache, $this->loadCache($factory, $path));
        self::assertFalse($mutex->isAcquired());
    }
}

final class RouterInitializationMutex implements Mutex
{
    private bool $acquired = false;
    public function acquire(): void
    {
        self::assertAvailable($this->acquired);
        $this->acquired = true;
    }
    public function release(): void
    {
        $this->acquired = false;
    }
    public function isAcquired(): bool
    {
        return $this->acquired;
    }
    private static function assertAvailable(bool $acquired): void
    {
        if ($acquired) {
            throw new \LogicException('Mutex already held.');
        }
    }
}
