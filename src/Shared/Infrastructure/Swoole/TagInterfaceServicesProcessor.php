<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\CompileProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes kernel.reset tags from interface-based services so the Swoole
 * proxifier doesn't attempt to proxy them (which triggers a warning since
 * interfaces can't be proxied). Interface services like HttpClientInterface
 * are stateless by design and safe to share across coroutine contexts.
 */
final class TagInterfaceServicesProcessor implements CompileProcessor
{
    public function process(ContainerBuilder $container, Proxifier $proxifier): void
    {
        $taggedServices = $container->findTaggedServiceIds('kernel.reset');

        foreach ($taggedServices as $serviceId => $tags) {
            if (!$container->has($serviceId)) {
                continue;
            }

            $definition = $container->findDefinition($serviceId);
            $class = $definition->getClass();

            if ($class === null || !interface_exists($class)) {
                continue;
            }

            $definition->clearTag('kernel.reset');
        }
    }
}
