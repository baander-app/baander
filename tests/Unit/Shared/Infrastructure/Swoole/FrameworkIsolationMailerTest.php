<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ProxyManager\Configuration;
use ProxyManager\Factory\AccessInterceptorValueHolderFactory;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use Swoole\Coroutine;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServicesPass;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Generator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Instantiator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\UnmanagedFactoryInstantiator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Component\Locking\Channel\ChannelMutexFactory;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\NullTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

final class FrameworkIsolationMailerTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSourcePipelinePoolsDirectSmtpFactoryOutputsForOverlappingSenders(): void
    {
        $container = new ContainerBuilder();
        $container->addResource(new FileResource(__FILE__));
        $container->setParameter('kernel.bundles', []);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_ENABLED, true);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_MAX_SVC_INSTANCES, 4);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_STATEFUL_SERVICES, []);
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_COMPILE_PROCESSORS, []);
        $container->setDefinition('kernel_proxy', (new Definition(FrameworkIsolationMailerKernel::class))->addTag(ContainerConstants::TAG_SAFE_STATEFUL_SERVICE));
        $container->setDefinition(ServicePoolContainer::class, new Definition(ServicePoolContainer::class));
        $container->setDefinition('services_resetter', new Definition(null, [new ServiceLocatorArgument([]), []]));
        $container->setDefinition('mailer.smtp_factory', (new Definition(EsmtpTransportFactory::class))->addTag('mailer.transport_factory'));
        $container->setDefinition('mailer.null_factory', (new Definition(NullTransportFactory::class))->addTag('mailer.transport_factory'));

        (new StatefulServicesPass())->process($container);

        $factoryDefinition = $container->getDefinition('mailer.smtp_factory');
        self::assertNotNull($factoryDefinition->getFactory());
        self::assertSame(UnmanagedFactoryInstantiator::class, (string) $factoryDefinition->getFactory()[0]);
        $configs = $factoryDefinition->getArgument(1);
        self::assertSame('create', $configs[0]['factoryMethod']);
        self::assertSame(EsmtpTransport::class, $configs[0]['returnType']);
        self::assertArrayNotHasKey('resetter', $configs[0]);
        self::assertNull($container->getDefinition('mailer.null_factory')->getFactory());

        $configuration = new Configuration();
        $configuration->setGeneratorStrategy(new EvaluatingGeneratorStrategy());
        $pools = new ServicePoolContainer([]);
        $mutexFactory = new ChannelMutexFactory(acquireTimeout: .5);
        $instantiator = new UnmanagedFactoryInstantiator(
            new AccessInterceptorValueHolderFactory($configuration),
            new Instantiator(new Generator($configuration)),
            $pools,
            $mutexFactory,
            $mutexFactory,
            new Swoole(),
            $mutexFactory,
        );
        $factory = $instantiator->newInstance(new EsmtpTransportFactory(), $configs, 4);
        $transport = $factory->create(new Dsn('smtp', 'smtp.baander.app', port: 1025));
        self::assertInstanceOf(\Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport::class, $transport);
        $streams = [];
        \Swoole\Coroutine\run(static function () use ($transport, $pools, &$streams): void {
            $ready = new Coroutine\Channel(2);
            for ($index = 0; $index < 2; ++$index) {
                Coroutine::create(static function () use ($transport, $pools, $ready, &$streams): void {
                    $streams[] = $transport->getStream();
                    $ready->push(true);
                    Coroutine::sleep(.01);
                    $pools->releaseFromCoroutine(Coroutine::getCid());
                });
            }
            $ready->pop();
            $ready->pop();
        });
        self::assertCount(2, $streams);
        self::assertNotSame($streams[0], $streams[1]);
    }
}

class FrameworkIsolationMailerKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [];
    }
    public function registerContainerConfiguration(LoaderInterface $loader): void {}
}
