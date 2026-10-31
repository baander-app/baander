<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Security;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\CompileProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use Symfony\Bundle\SecurityBundle\Security\LazyFirewallContext;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Component\Security\Http\Firewall\ContextListener;

final class SecurityProcessor implements CompileProcessor
{
    private const string ACCESS_DECISION_MANAGER_ID = 'security.access.decision_manager';
    private const string DEBUG_ACCESS_DECISION_MANAGER_ID = 'debug.security.access.decision_manager';
    private const string DEBUG_FIREWALL_LISTENER_ID = 'debug.security.firewall';
    private const string AUTHENTICATOR_RESETTER_ID = 'swoole_bundle.security.traceable_authenticator_resetter';
    private const string CONTEXT_LISTENER_RESETTER_ID
        = 'swoole_bundle.coroutines_support.security.context_listener_resetter';

    public function process(ContainerBuilder $container, Proxifier $proxifier): void
    {
        /** @var array<string,string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        if (!isset($bundles['SecurityBundle'])) {
            return;
        }

        $this->processAccessDecisionManagerPair($container);
        $this->processLazyFirewallContexts($container);
        $this->processContextListeners($container);
        $this->processTraceableAuthenticators($container);
    }

    private function processTraceableAuthenticators(ContainerBuilder $container): void
    {
        $tagged = false;

        foreach ($container->getDefinitions() as $definition) {
            if ($definition->isAbstract() || $definition->getClass() !== TraceableAuthenticator::class) {
                continue;
            }

            if ($this->hasAbstractArguments($definition)) {
                continue;
            }

            $definition->addTag(ContainerConstants::TAG_STATEFUL_SERVICE, [
                'resetter' => self::AUTHENTICATOR_RESETTER_ID,
            ]);
            $tagged = true;
        }

        if ($tagged) {
            $container->setDefinition(self::AUTHENTICATOR_RESETTER_ID, new Definition(TraceableAuthenticatorResetter::class));
        }
    }

    private function processContextListeners(ContainerBuilder $container): void
    {
        $tagged = 0;

        foreach ($container->getDefinitions() as $definition) {
            if ($definition->isAbstract() || $definition->getClass() !== ContextListener::class) {
                continue;
            }

            // SecurityBundle keeps a template listener around for the per-firewall ones to be built from,
            // and leaves the arguments only a firewall can fill in - the provider key, here - standing as
            // abstract. It is not marked abstract itself, so there is nothing else to tell it apart, and
            // pooling something that cannot be instantiated fails the container build.
            if ($this->hasAbstractArguments($definition)) {
                continue;
            }

            $definition->addTag(
                ContainerConstants::TAG_STATEFUL_SERVICE,
                ['resetter' => self::CONTEXT_LISTENER_RESETTER_ID],
            );
            $tagged++;
        }

        if ($tagged === 0) {
            return;
        }

        $container->setDefinition(self::CONTEXT_LISTENER_RESETTER_ID, new Definition(ContextListenerResetter::class));
    }

    private function hasAbstractArguments(Definition $definition): bool
    {
        foreach ($definition->getArguments() as $argument) {
            if ($argument instanceof AbstractArgument) {
                return true;
            }
        }

        return false;
    }

    private function processLazyFirewallContexts(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::DEBUG_FIREWALL_LISTENER_ID)) {
            return;
        }

        foreach ($container->getDefinitions() as $definition) {
            // Exact matching avoids autoloading optional service classes.
            if ($definition->isAbstract() || $definition->getClass() !== LazyFirewallContext::class) {
                continue;
            }

            $definition->setShared(false);
        }
    }

    private function processAccessDecisionManagerPair(ContainerBuilder $container): void
    {
        $decoratedId = self::DEBUG_ACCESS_DECISION_MANAGER_ID . '.inner';

        // DecoratorServicePass has already run by the time compile processors do, and it leaves the
        // decorator answering to its own id with the decorated manager moved to `.inner` - while
        // `security.access.decision_manager` becomes a plain alias to the decorator. Going by the
        // presence of `.inner` is what tells the two shapes apart; asking hasDefinition() about an
        // alias only ever answers false.
        if ($container->hasDefinition($decoratedId)) {
            // the decorator holds on to the manager it wraps, so the two have to travel together: one
            // manager per pooled decorator instead of one shared by all of them
            $container->getDefinition($decoratedId)
                ->setShared(false);

            $container->getDefinition(self::DEBUG_ACCESS_DECISION_MANAGER_ID)
                ->addTag(ContainerConstants::TAG_STATEFUL_SERVICE);

            return;
        }

        if (!$container->hasDefinition(self::ACCESS_DECISION_MANAGER_ID)) {
            return;
        }

        $container->getDefinition(self::ACCESS_DECISION_MANAGER_ID)
            ->addTag(ContainerConstants::TAG_STATEFUL_SERVICE);
    }
}
