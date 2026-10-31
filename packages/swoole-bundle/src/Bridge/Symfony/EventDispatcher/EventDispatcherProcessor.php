<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\EventDispatcher;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\CompileProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class EventDispatcherProcessor implements CompileProcessor
{
    private const string SECURITY_FIREWALLS_PARAM = 'security.firewalls';
    private const string SECURITY_EVENT_DISPATCHER_ID_PREFIX = 'security.event_dispatcher.';

    public function process(ContainerBuilder $container, Proxifier $proxifier): void
    {
        $this->processDispatcherPair($container, 'event_dispatcher', 'debug.event_dispatcher');

        foreach ($this->firewallDispatcherIds($container) as $dispatcherId) {
            $this->processDispatcherPair($container, $dispatcherId, 'debug.' . $dispatcherId);
        }
    }

    /** @return list<string> */
    private function firewallDispatcherIds(ContainerBuilder $container): array
    {
        if (!$container->hasParameter(self::SECURITY_FIREWALLS_PARAM)) {
            return [];
        }

        /** @var list<string> $firewallNames */
        $firewallNames = $container->getParameter(self::SECURITY_FIREWALLS_PARAM);
        $dispatcherIds = [];

        foreach ($firewallNames as $firewallName) {
            $dispatcherId = self::SECURITY_EVENT_DISPATCHER_ID_PREFIX . $firewallName;

            // DecoratorServicePass replaces decorated ids with aliases before this processor runs.
            if (!$container->has($dispatcherId)) {
                continue;
            }

            $dispatcherIds[] = $dispatcherId;
        }

        return $dispatcherIds;
    }

    private function processDispatcherPair(
        ContainerBuilder $container,
        string $dispatcherId,
        string $debugDispatcherId,
    ): void {
        if (!$container->has($dispatcherId)) {
            return;
        }

        if (
            !$container->hasDefinition($debugDispatcherId)
            || !$container->hasDefinition($debugDispatcherId . '.inner')
        ) {
            $container->findDefinition($dispatcherId)->addTag(ContainerConstants::TAG_STATEFUL_SERVICE);

            return;
        }

        // the debug event dispatcher needs to be coupled to the original event dispatcher, because
        // it registers listeners to the original dispatcher
        $container->findDefinition($debugDispatcherId . '.inner')
            ->setShared(false);

        $debugDispatcherDef = $container->getDefinition($debugDispatcherId);
        $debugDispatcherDef->addTag(ContainerConstants::TAG_STATEFUL_SERVICE);
    }
}
