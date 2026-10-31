<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use Doctrine\DBAL\DriverManager;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use ProxyManager\Configuration;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\ContextualRegistry;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DoctrineProcessor;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DBAL\DBALPlatformAliveKeeper;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\ORM\EntityManagerResetter;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\ORM\EntityManagerStabilityChecker;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Generator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\ClassModificationProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\BaseServicePool;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class ContextualDoctrineRegistryTest extends TestCase
{
    public function testProcessorPreservesRegistryArgumentsAndSuppliesTheExecutionContext(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['DoctrineBundle' => 'Doctrine\\Bundle\\DoctrineBundle\\DoctrineBundle']);
        $container->setParameter('doctrine.entity_managers', []);
        $container->setParameter('doctrine.connections', []);
        $arguments = [new Reference('service_container'), [], [], 'default', 'default'];
        $container->setDefinition('doctrine', new Definition(Registry::class, $arguments));
        $container->setDefinition(DBALPlatformAliveKeeper::class, new Definition(DBALPlatformAliveKeeper::class, [null, []]));
        $proxifier = new Proxifier($container, new ClassModificationProcessor($container));

        (new DoctrineProcessor())->process($container, $proxifier);

        $definition = $container->getDefinition('doctrine');
        self::assertSame(ContextualRegistry::class, $definition->getClass());
        self::assertSame($arguments, array_slice($definition->getArguments(), 0, 5));
        self::assertSame(Swoole::class, (string) $definition->getArgument(5));
    }

    public function testResetReplacesOnlyTheCurrentManagerWithoutTurningTheWrapperIntoANativeLazyObject(): void
    {
        $swoole = new Swoole();
        $mutex = new class implements Mutex {
            public function acquire(): void {}
            public function release(): void {}
            public function isAcquired(): bool
            {
                return false;
            }
        };
        $pool = new class ($swoole, $mutex, 4, new EntityManagerResetter(), new EntityManagerStabilityChecker()) extends BaseServicePool {
            protected function newServiceInstance(): EntityManager
            {
                $configuration = ORMSetup::createAttributeMetadataConfiguration([], true);
                $configuration->enableNativeLazyObjects(true);

                return new EntityManager(
                    DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
                    $configuration,
                );
            }
        };
        $configuration = new Configuration();
        $configuration->setGeneratorStrategy(new EvaluatingGeneratorStrategy());
        $proxy = (new Generator($configuration))->createProxy($pool, EntityManager::class);
        self::assertInstanceOf(EntityManager::class, $proxy);
        $container = new class ($proxy) extends Container {
            public function __construct(object $manager)
            {
                $this->services['manager'] = $manager;
                $this->methodMap['manager'] = 'getManagerService';
            }

            protected static function getManagerService(self $container): object
            {
                return $container->services['manager'];
            }
        };
        $registry = new ContextualRegistry($container, [], ['default' => 'manager'], 'default', 'default', $swoole);

        Coroutine\run(function () use ($registry, $pool, $proxy): void {
            $ready = new Channel(1);
            $resume = new Channel(1);
            $finished = new Channel(1);
            Coroutine::create(function () use ($pool, $proxy, $ready, $resume, $finished): void {
                $other = $pool->get();
                self::assertTrue($proxy->isOpen());
                $ready->push(true);
                $resume->pop();
                self::assertSame($other, $pool->get());
                self::assertTrue($proxy->isOpen());
                $finished->push(true);
            });
            $ready->pop();
            $closed = $pool->get();
            $proxy->close();
            self::assertFalse($closed->isOpen());

            $registry->reset();

            self::assertSame($proxy, $registry->getManager());
            self::assertTrue($proxy->isOpen());
            self::assertNotSame($closed, $pool->get());
            self::assertFalse((new \ReflectionClass($proxy))->isUninitializedLazyObject($proxy));

            // Explicit reset also replaces an open assignment.
            $open = $pool->get();
            self::assertSame($proxy, $registry->resetManager());
            self::assertFalse($open->isOpen());
            self::assertTrue($proxy->isOpen());
            self::assertNotSame($open, $pool->get());
            $resume->push(true);
            $finished->pop();
        });
    }
}
