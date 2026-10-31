<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Cache;

use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\CompileProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\SimpleResetter;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Throwable;

final class CacheAdapterProcessor implements CompileProcessor
{
    public function process(ContainerBuilder $container, Proxifier $proxifier): void
    {
        foreach ($container->getDefinitions() as $definition) {
            if ($definition->isAbstract() || $this->hasAbstractArguments($definition)) {
                continue;
            }
            $className = $this->nameSystemCacheClass($definition) ?? $definition->getClass();
            try {
                if ($className === null || !class_exists($className)) {
                    continue;
                }
            } catch (Throwable) {
                // Optional integrations may throw from their autoloader when a dependency is absent.
                continue;
            }

            if (is_a($className, TraceableAdapter::class, true)) {
                // reset() would also reset a shared ArrayAdapter and erase cached values.
                $this->tag($container, $definition, 'clearCalls', 'cache_traceable_adapter_resetter');
            } elseif (is_subclass_of($className, AbstractAdapter::class) || $className === ChainAdapter::class) {
                $this->tag($container, $definition, 'reset', 'cache_adapter_resetter');
            }
        }
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

    private function nameSystemCacheClass(Definition $definition): ?string
    {
        if ($definition->getClass() !== AdapterInterface::class) {
            return null;
        }
        if ($definition->getFactory() !== [AbstractAdapter::class, 'createSystemCache']) {
            return null;
        }

        // Match AbstractAdapter::createSystemCache(); compilation and workers use the same CLI runtime.
        $className = ApcuAdapter::isSupported() && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL))
            ? ChainAdapter::class : PhpFilesAdapter::class;
        $definition->setClass($className);

        return $className;
    }

    private function tag(ContainerBuilder $container, Definition $definition, string $method, string $suffix): void
    {
        $resetterId = 'swoole_bundle.coroutines_support.' . $suffix;
        $definition->addTag(ContainerConstants::TAG_STATEFUL_SERVICE, ['resetter' => $resetterId]);
        if (!$container->hasDefinition($resetterId)) {
            $container->setDefinition($resetterId, new Definition(SimpleResetter::class, [$method]));
        }
    }
}
