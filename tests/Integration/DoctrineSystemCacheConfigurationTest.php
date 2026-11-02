<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Transcode\Infrastructure\Doctrine\Entity\TranscodeJobEntity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;

final class DoctrineSystemCacheConfigurationTest extends TestCase
{
    public function testProductionMetadataAndQueriesUseLocalCachesWithoutConnectingToRedis(): void
    {
        $directory = sys_get_temp_dir() . '/baander-doctrine-cache-' . bin2hex(random_bytes(8));
        $first = new DoctrineSystemCacheKernel($directory . '/first');
        $second = new DoctrineSystemCacheKernel($directory . '/second');
        try {
            $first->boot();
            $container = $first->getContainer();
            $pool = $container->get('doctrine.system_cache_pool');
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            $registry = $container->get('doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $registry);
            $manager = $registry->getManager();
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            self::assertSame($pool, $manager->getConfiguration()->getMetadataCache());
            self::assertSame($pool, $manager->getConfiguration()->getQueryCache());
            $metadata = $manager->getClassMetadata(TranscodeJobEntity::class);
            self::assertTrue($metadata->hasField('requestId'));
            self::assertTrue($metadata->hasField('presentationKey'));
            self::assertTrue($metadata->hasField('presentationSelection'));

            $item = $pool->getItem('previous-build-metadata');
            $item->set('stale mapping');
            self::assertTrue($pool->save($item));
            self::assertTrue($pool->getItem('previous-build-metadata')->isHit());

            $second->boot();
            $nextPool = $second->getContainer()->get('doctrine.system_cache_pool');
            self::assertInstanceOf(CacheItemPoolInterface::class, $nextPool);
            self::assertFalse($nextPool->getItem('previous-build-metadata')->isHit());
        } finally {
            $first->shutdown();
            $second->shutdown();
            new Filesystem()->remove($directory);
        }
    }

    public static function rejectRedisConnection(): never
    {
        throw new \LogicException('Code metadata must not connect to Redis.');
    }
}

final class DoctrineSystemCacheKernel extends Kernel
{
    public function __construct(private readonly string $directory)
    {
        parent::__construct('prod', false);
    }

    public function getProjectDir(): string { return dirname(__DIR__, 2); }
    public function getCacheDir(): string { return $this->directory . '/cache'; }
    public function getLogDir(): string { return $this->directory . '/log'; }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition('doctrine.system_cache_pool')->setPublic(true);
                // Result caching has a separate Redis contract; this test only loads code metadata.
                $container->setDefinition('doctrine.result_cache_pool', new Definition(ArrayAdapter::class));
                foreach ($container->getDefinitions() as $definition) {
                    if ($definition->getFactory() === [AbstractAdapter::class, 'createConnection']) {
                        $definition->setFactory([DoctrineSystemCacheConfigurationTest::class, 'rejectRedisConnection']);
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
    }
}
