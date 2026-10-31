<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Twig;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\CompileProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TwigProcessor implements CompileProcessor
{
    private const string PROFILE_ID = 'twig.profile';
    private const string DATA_COLLECTOR_ID = 'data_collector.twig';
    private const string ENVIRONMENT_ID = 'twig';

    // Extensions use their concrete class as a compiled-template key, so they cannot be proxied.
    private const array PROFILER_EXTENSION_IDS = [
        'twig.extension.profiler',
        'twig.extension.webprofiler',
    ];

    public function process(ContainerBuilder $container, Proxifier $proxifier): void
    {
        $this->unshareProfilerExtensions($container);

        if (!$container->hasDefinition(self::PROFILE_ID)) {
            return;
        }

        $container->getDefinition(self::PROFILE_ID)
            ->addTag(ContainerConstants::TAG_STATEFUL_SERVICE);

        // the profile exists whenever the profiler extension does; the collector only with the profiler
        // itself, so an application collecting nothing keeps Symfony's own class
        if (!$container->hasDefinition(self::DATA_COLLECTOR_ID)) {
            return;
        }

        $container->getDefinition(self::DATA_COLLECTOR_ID)
            ->setClass(UnwrappingTwigDataCollector::class);
    }

    private function unshareProfilerExtensions(ContainerBuilder $container): void
    {
        // WebProfilerBundle registers its extension whether or not Twig is installed, and an extension
        // no Environment ever receives is constructed by nobody - unsharing it would only make the
        // container harder to read.
        if (!$container->hasDefinition(self::ENVIRONMENT_ID)) {
            return;
        }

        foreach (self::PROFILER_EXTENSION_IDS as $extensionId) {
            if (!$container->hasDefinition($extensionId)) {
                continue;
            }

            $container->getDefinition($extensionId)->setShared(false);
        }
    }
}
