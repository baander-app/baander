<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpClient;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\CompileProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpClientProcessor implements CompileProcessor
{
    public function process(ContainerBuilder $container, Proxifier $proxifier): void
    {
        foreach ($container->getDefinitions() as $definition) {
            // Exact matching avoids autoloading definitions for absent optional integrations.
            if ($definition->getClass() !== TraceableHttpClient::class) {
                continue;
            }

            if ($definition->hasTag(ContainerConstants::TAG_STATEFUL_SERVICE)) {
                continue;
            }

            $definition->addTag(ContainerConstants::TAG_STATEFUL_SERVICE);
        }

        $this->makeTheTransportPoolable($container);
    }

    private function makeTheTransportPoolable(ContainerBuilder $container): void
    {
        // Decoration renames the original transport, so match its factory rather than its service id.
        foreach ($container->getDefinitions() as $transport) {
            if (
                $transport->getClass() !== HttpClientInterface::class
                || $transport->getFactory() !== [HttpClient::class, 'create']
            ) {
                continue;
            }

            $transport->setClass(HttpClient::create()::class);
        }
    }
}
