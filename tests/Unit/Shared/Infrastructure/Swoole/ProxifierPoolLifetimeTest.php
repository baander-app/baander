<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use ProxyManager\Configuration;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\ClassModificationProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Generator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Instantiator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Component\Locking\Channel\ChannelMutex;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
use SwooleBundle\SwooleBundle\Component\Locking\MutexFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ProxifierPoolLifetimeTest extends TestCase
{
    public function testRepeatedNonSharedLookupsKeepOneWorkerPoolAndResetEachAssignment(): void
    {
        self::assertTrue(extension_loaded('ffi'), 'FFI is required; this proof must not skip.');
        $container = new ContainerBuilder();
        $container->setParameter(ContainerConstants::PARAM_COROUTINES_MAX_SVC_INSTANCES, 5);
        $registry = new ServicePoolContainer([]);
        $container->set(ServicePoolContainer::class, $registry);
        $container->set(Swoole::class, new Swoole());
        $configuration = new Configuration();
        $configuration->setProxiesNamespace('ProxifierPoolLifetime' . bin2hex(random_bytes(8)));
        $configuration->setGeneratorStrategy(new EvaluatingGeneratorStrategy());
        $container->set(Instantiator::class, new Instantiator(new Generator($configuration)));
        $container->set('swoole_bundle.service_pool.locking', new class implements MutexFactory {
            public function newMutex(): Mutex
            {
                return new ChannelMutex();
            }
        });
        $container->register('probe', ProxifierPoolLifetimeService::class)->setPublic(true)->setShared(false);
        $proxifier = new Proxifier($container, new ClassModificationProcessor($container));
        $proxifier->proxifyService('probe', 'reset');
        foreach ($proxifier->getProxifiedServicePoolRefs() as $priority => $references) {
            foreach ($references as $reference) {
                $registry->addPool($container->get((string) $reference), $priority);
            }
        }
        $previous = null;
        ProxifierPoolLifetimeService::$resetCount = 0;

        for ($index = 0; $index < 4; ++$index) {
            $proxy = $container->get('probe');
            self::assertNotSame($previous, $proxy);
            self::assertSame('ready', $proxy->value());
            $registry->releaseFromCoroutine(-1);
            $previous = $proxy;
        }

        self::assertSame(1, $registry->count(), 'A nonshared proxy must not append a new worker pool on each lookup.');
        self::assertSame(4, ProxifierPoolLifetimeService::$resetCount);
        $pool = $registry->getPools()[0];
        self::assertSame(0, $pool->getAssignedCount());
        self::assertSame(1, $pool->getFreeCount());
    }
}

class ProxifierPoolLifetimeService
{
    public static int $resetCount = 0;

    public function value(): string
    {
        return 'ready';
    }

    public function reset(): void
    {
        ++self::$resetCount;
    }
}
