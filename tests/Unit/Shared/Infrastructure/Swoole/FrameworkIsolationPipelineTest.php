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
use Symfony\Bundle\WebProfilerBundle\Twig\WebProfilerExtension;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\UsageTrackingTokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;

final class FrameworkIsolationPipelineTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDefaultProcessorsRunBeforeAppTagStrippingAndCreateOnePoolWithResetPerService(): void
    {
        $container = $this->container(
            bundles: ['SecurityBundle' => 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle'],
            processors: [['class' => TagInterfaceServicesProcessor::class, 'priority' => 0]],
        );
        $container->setDefinition('event_dispatcher', new Definition(FrameworkIsolationResettableDispatcher::class)
            ->addTag('kernel.reset', ['method' => 'reset']));
        $container->setDefinition('security.access.decision_manager', new Definition(AccessDecisionManager::class));
        $container->setDefinition('http_client.uri_template.inner', new Definition(HttpClientInterface::class)
            ->setFactory([HttpClient::class, 'create'])->addTag('kernel.reset', ['method' => '?reset']));
        $container->setDefinition('traced', new Definition(TraceableHttpClient::class));

        new StatefulServicesPass()->process($container);

        self::assertNull($container->getDefinition('event_dispatcher.swoole_coop.wrapped')->getFactory());
        self::assertNull($container->getDefinition('security.access.decision_manager.swoole_coop.wrapped')->getFactory());
        $transport = $container->getDefinition('http_client.uri_template.inner.swoole_coop.wrapped');
        self::assertSame(HttpClient::create()::class, $transport->getClass());
        self::assertSame([HttpClient::class, 'create'], $transport->getFactory());
        self::assertTrue($container->getDefinition('http_client.uri_template.inner')->hasTag('kernel.reset'));
        self::assertSame(['reset'], $this->poolResetter($container, 'traced')->getArgument(0));
        $poolGroups = $container->getDefinition(ServicePoolContainer::class)->getArgument(0);
        $groups = array_values($poolGroups);
        $poolIds = array_map(strval(...), array_merge(...$groups));
        self::assertCount(4, $poolIds);
        self::assertCount(4, array_unique($poolIds));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPoolResetterRunsEveryKernelResetMethodInTagOrder(): void
    {
        $container = $this->container(['security.token_storage' => ['disableUsageTracking', 'setToken']]);
        $container->setDefinition('security.token_storage', new Definition(UsageTrackingTokenStorage::class)
            ->addTag('kernel.reset', ['method' => 'disableUsageTracking'])
            ->addTag('kernel.reset', ['method' => 'setToken']));

        new StatefulServicesPass()->process($container);

        $resetter = $this->poolResetter($container, 'security.token_storage');
        self::assertSame(['disableUsageTracking', 'setToken'], $resetter->getArgument(0));
        $shared = new TokenStorage();
        $storage = new UsageTrackingTokenStorage($shared, new Container());
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('listener', null), 'api'));

        new SimpleResetter($resetter->getArgument(0))->reset($storage);

        self::assertNull($shared->getToken());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOptionalResetMethodIsKeptOnlyWhenTheClassDeclaresIt(): void
    {
        $container = $this->container(['optional_reset' => ['?absentReset', '?presentReset']]);
        $container->setDefinition('optional_reset', new Definition(FrameworkIsolationOptionalResetService::class)
            ->addTag('kernel.reset', ['method' => 'absentReset', 'on_invalid' => 'ignore'])
            ->addTag('kernel.reset', ['method' => 'presentReset', 'on_invalid' => 'ignore']));

        new StatefulServicesPass()->process($container);

        $resetter = $this->poolResetter($container, 'optional_reset');
        self::assertSame(['presentReset'], $resetter->getArgument(0));
        $service = new FrameworkIsolationOptionalResetService();

        new SimpleResetter($resetter->getArgument(0))->reset($service);

        self::assertSame(1, $service->resets);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResetterOnStatefulTagReplacesKernelResetMethods(): void
    {
        $container = $this->container(['cache.traced' => ['reset']]);
        $container->setDefinition('cache.traced', new Definition(TraceableAdapter::class)
            ->addTag('kernel.reset', ['method' => 'reset']));

        new StatefulServicesPass()->process($container);

        $resetter = $container->getDefinition('cache.traced.swoole_coop.service_pool')->getArgument(5);
        self::assertInstanceOf(Reference::class, $resetter);
        self::assertSame('swoole_bundle.coroutines_support.cache_traceable_adapter_resetter', (string) $resetter);
        self::assertSame(['clearCalls'], $container->getDefinition((string) $resetter)->getArgument(0));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testProfilerExtensionTaggedKernelResetIsNotPooledSoTwigKeysItByItsOwnClass(): void
    {
        $container = $this->container(['twig.extension.webprofiler' => ['reset']]);
        $container->setDefinition('twig', new Definition(Environment::class));
        $container->setDefinition('twig.extension.webprofiler', new Definition(WebProfilerExtension::class)
            ->addTag('kernel.reset', ['method' => 'reset']));

        new StatefulServicesPass()->process($container);

        $extension = $container->getDefinition('twig.extension.webprofiler');
        self::assertSame(WebProfilerExtension::class, $extension->getClass());
        self::assertNull($extension->getFactory());
        self::assertFalse($extension->isShared());
        self::assertFalse($container->hasDefinition('twig.extension.webprofiler.swoole_coop.service_pool'));
    }

    /**
     * @param array<string, list<string>> $resetMethods the method map ResettableServicePass hands to services_resetter
     * @param array<string, string> $bundles
     * @param list<array{class: class-string, priority: int}> $processors
     */
    private function container(array $resetMethods = [], array $bundles = [], array $processors = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addResource(new FileResource(__FILE__));
        $container->setParameter('kernel.bundles', $bundles);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_ENABLED, true);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_MAX_SVC_INSTANCES, 4);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_STATEFUL_SERVICES, []);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_COMPILE_PROCESSORS, $processors);
        $container->setDefinition('kernel_proxy', new Definition(FrameworkIsolationKernel::class)
            ->addTag(ContainerConstants::TAG_SAFE_STATEFUL_SERVICE));
        $container->setDefinition(ServicePoolContainer::class, new Definition(ServicePoolContainer::class));
        $ids = array_keys($resetMethods);
        $resettable = array_combine($ids, array_map(static fn(string $id): Reference => new Reference($id), $ids));
        $container->setDefinition('services_resetter', new Definition(null, [new ServiceLocatorArgument($resettable), $resetMethods]));

        return $container;
    }

    private function poolResetter(ContainerBuilder $container, string $serviceId): Definition
    {
        $resetter = $container->getDefinition($serviceId . '.swoole_coop.service_pool')->getArgument(5);
        self::assertInstanceOf(Definition::class, $resetter);
        self::assertSame(SimpleResetter::class, $resetter->getClass());

        return $resetter;
    }
}

class FrameworkIsolationOptionalResetService
{
    public int $resets = 0;

    public function presentReset(): void
    {
        ++$this->resets;
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
