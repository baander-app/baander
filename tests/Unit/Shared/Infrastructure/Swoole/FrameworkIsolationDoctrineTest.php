<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Doctrine\DBAL\DBALPlatformAliveKeeper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServicesPass;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\SimpleResetter;
use Symfony\Bridge\Doctrine\ContainerAwareEventManager;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;

final class FrameworkIsolationDoctrineTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function holderRegistrations(): iterable
    {
        yield 'current kernel reset registration' => [true];
        yield 'untagged holder registration' => [false];
    }

    #[DataProvider('holderRegistrations')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDoctrinePipelineOwnsEventManagersAndResetsQueryHistory(bool $kernelReset): void
    {
        $container = new ContainerBuilder();
        $container->addResource(new FileResource(__FILE__));
        $container->setParameter('kernel.bundles', ['DoctrineBundle' => 'Doctrine\\Bundle\\DoctrineBundle\\DoctrineBundle']);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection']);
        $container->setParameter('doctrine.entity_managers', []);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_ENABLED, true);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_MAX_SVC_INSTANCES, 4);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_STATEFUL_SERVICES, []);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_COMPILE_PROCESSORS, []);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_DOCTRINE_COMPILE_PROCESSOR_CONFIG, ['global_limit' => 2]);
        $container->setDefinition('kernel_proxy', (new Definition(FrameworkIsolationDoctrineKernel::class))->addTag(ContainerConstants::TAG_SAFE_STATEFUL_SERVICE));
        $container->setDefinition('doctrine', new Definition(\stdClass::class));
        $container->setDefinition('doctrine.dbal.default_connection', new Definition(Connection::class));
        $container->setDefinition('doctrine.dbal.default_connection.event_manager', new Definition(ContainerAwareEventManager::class));
        $container->setDefinition(DBALPlatformAliveKeeper::class, new Definition(DBALPlatformAliveKeeper::class, [null, []]));
        $holder = new Definition(BacktraceDebugDataHolder::class, [['default']]);
        if ($kernelReset) {
            $holder->addTag('kernel.reset', ['method' => 'reset']);
        }
        $container->setDefinition('doctrine.debug_data_holder', $holder);
        $container->setDefinition('services_resetter', new Definition(null, [
            new ServiceLocatorArgument($kernelReset ? ['doctrine.debug_data_holder' => new Reference('doctrine.debug_data_holder')] : []),
            $kernelReset ? ['doctrine.debug_data_holder' => ['reset']] : [],
        ]));
        $container->setDefinition(ServicePoolContainer::class, new Definition(ServicePoolContainer::class));

        (new StatefulServicesPass())->process($container);

        $eventPool = $container->getDefinition('doctrine.dbal.default_connection.event_manager.swoole_coop.service_pool');
        self::assertSame(2, $eventPool->getArgument(4));
        self::assertFalse($container->getDefinition('doctrine.dbal.default_connection.event_manager.swoole_coop.wrapped')->isShared());
        $resetter = $container->getDefinition('doctrine.debug_data_holder.swoole_coop.service_pool')->getArgument(5);
        self::assertInstanceOf(Definition::class, $resetter);
        self::assertSame(SimpleResetter::class, $resetter->getClass());
        self::assertSame('reset', $resetter->getArgument(0));
        self::assertArrayHasKey('doctrine.debug_data_holder', $container->getDefinition('services_resetter')->getArgument(1));
        self::assertNull($container->getDefinition('doctrine.debug_data_holder.swoole_coop.wrapped')->getFactory());
        $queryHistory = new BacktraceDebugDataHolder(['default']);
        $queryHistory->addQuery('default', new Query('SELECT 1'));
        self::assertNotEmpty($queryHistory->getData());
        (new SimpleResetter($resetter->getArgument(0)))->reset($queryHistory);
        self::assertSame([], $queryHistory->getData());
    }
}

class FrameworkIsolationDoctrineKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [];
    }
    public function registerContainerConfiguration(LoaderInterface $loader): void {}
}
