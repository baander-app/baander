<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\TagInterfaceServicesProcessor;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServicesPass;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\SimpleResetter;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

final class FrameworkIsolationPipelineTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDefaultProcessorsRunBeforeAppTagStrippingAndCreateOnePoolWithResetPerService(): void
    {
        $container = new ContainerBuilder();
        $container->addResource(new FileResource(__FILE__));
        $container->setParameter('kernel.bundles', ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle']);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_ENABLED, true);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_MAX_SVC_INSTANCES, 4);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_STATEFUL_SERVICES, []);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_COMPILE_PROCESSORS, [
            ['class' => TagInterfaceServicesProcessor::class, 'priority' => 0],
        ]);
        $container->setDefinition('kernel_proxy', (new Definition(FrameworkIsolationKernel::class))
            ->addTag(ContainerConstants::TAG_SAFE_STATEFUL_SERVICE));
        $container->setDefinition(ServicePoolContainer::class, new Definition(ServicePoolContainer::class));
        $container->setDefinition('services_resetter', new Definition(null, [new ServiceLocatorArgument([]), []]));
        $container->setDefinition('event_dispatcher', (new Definition(FrameworkIsolationResettableDispatcher::class))
            ->addTag('kernel.reset', ['method' => 'reset']));
        $container->setDefinition('security.access.decision_manager', new Definition(AccessDecisionManager::class));
        $container->setDefinition('http_client.uri_template.inner', (new Definition(HttpClientInterface::class))
            ->setFactory([HttpClient::class, 'create'])->addTag('kernel.reset', ['method' => '?reset']));
        $container->setDefinition('traced', new Definition(TraceableHttpClient::class));

        (new StatefulServicesPass())->process($container);

        self::assertNull($container->getDefinition('event_dispatcher.swoole_coop.wrapped')->getFactory());
        self::assertNull($container->getDefinition('security.access.decision_manager.swoole_coop.wrapped')->getFactory());
        $transport = $container->getDefinition('http_client.uri_template.inner.swoole_coop.wrapped');
        self::assertSame(HttpClient::create()::class, $transport->getClass());
        self::assertSame([HttpClient::class, 'create'], $transport->getFactory());
        self::assertTrue($container->getDefinition('http_client.uri_template.inner')->hasTag('kernel.reset'));
        $resetter = $container->getDefinition('traced.swoole_coop.service_pool')->getArgument(5);
        self::assertInstanceOf(Definition::class, $resetter);
        self::assertSame(SimpleResetter::class, $resetter->getClass());
        self::assertSame('reset', $resetter->getArgument(0));
        $poolGroups = $container->getDefinition(ServicePoolContainer::class)->getArgument(0);
        $poolIds = array_map(strval(...), array_merge(...array_values($poolGroups)));
        self::assertCount(4, $poolIds);
        self::assertCount(4, array_unique($poolIds));
    }
}

class FrameworkIsolationResettableDispatcher extends EventDispatcher implements ResetInterface
{
    public function reset(): void {}
}

class FrameworkIsolationKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [];
    }
    public function registerContainerConfiguration(LoaderInterface $loader): void {}
}
