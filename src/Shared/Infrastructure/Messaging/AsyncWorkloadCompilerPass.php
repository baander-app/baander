<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use App\Shared\Application\Messaging\MessagePayloadCodecInterface;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Serialization\SigningSerializer;

/** Checks the effective Messenger definitions after handlers and decorators have been compiled. */
final class AsyncWorkloadCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $catalog = new AsyncWorkloadCatalog(
            $container->getParameter('app.messenger.workloads'),
            $container->getParameter('app.messenger.transport_policies'),
        );
        $locatorId = 'App\\Shared\\Infrastructure\\Messenger\\ValidatedSendersLocator.inner';
        $senders = $container->findDefinition($container->has($locatorId) ? $locatorId : 'messenger.senders_locator');
        $expectedRoutes = [];
        foreach ($catalog->workloads() as $class => $workload) {
            if ($workload['default']) {
                $expectedRoutes[$class] = [$workload['transport']];
            }
        }
        $routes = $senders->getArgument(0);
        if (!is_array($routes)) {
            throw new \LogicException('Messenger routing must contain exactly the catalog default routes.');
        }
        foreach ($routes as $class => $aliases) {
            if (!isset($expectedRoutes[$class])) {
                throw new \LogicException(sprintf('Messenger routing for "%s" is not a default catalog workload.', $class));
            }
        }
        foreach ($expectedRoutes as $class => $aliases) {
            if (($routes[$class] ?? null) !== $aliases) {
                throw new \LogicException(sprintf('Messenger routing for "%s" must select only "%s".', $class, $aliases[0]));
            }
        }

        $codecClasses = $this->codecClasses($container);
        $busDefinition = $container->findDefinition('messenger.default_bus');
        $bus = $busDefinition->getTag('container.decorator')[0]['id']
            ?? (string) $container->getAlias('messenger.default_bus');
        $handlerLocatorId = $bus . '.messenger.handlers_locator';
        $handlers = $container->hasDefinition($handlerLocatorId)
            ? $container->getDefinition($handlerLocatorId)->getArgument(0) : [];
        foreach ($catalog->workloads() as $class => $workload) {
            if (!isset($codecClasses[$class])) {
                throw new \LogicException(sprintf('Workload "%s" on "%s" has no registered payload codec.', $class, $workload['transport']));
            }
            if (!$this->hasEligibleHandler($container, $handlers, $class, $workload['transport'])) {
                throw new \LogicException(sprintf('Workload "%s" has no eligible handler on bus "%s" for transport "%s".', $class, $bus, $workload['transport']));
            }
        }

        $retryReferences = $container->findDefinition('messenger.retry_strategy_locator')->getArgument(0);
        foreach ($catalog->transportPolicies() as $transport => $policy) {
            $definition = $container->findDefinition('messenger.transport.' . $transport);
            if ($definition->getFactory() === [\App\Shared\Infrastructure\Messenger\OrdinaryAdmissionTransport::class, 'forEnvironment']) {
                $definition = $this->definition($container, $definition->getArgument(0))
                    ?? throw new \LogicException('Admission requires a concrete inner transport.');
            }
            $serializer = $this->definition($container, $definition->getArgument(2));
            if ($serializer?->getClass() === SigningSerializer::class) {
                $serializer = $this->definition($container, $serializer->getArgument(0));
            }
            if ($serializer?->getClass() !== JsonTransportSerializer::class) {
                throw new \LogicException(sprintf('Transport "%s" must use the JSON transport serializer.', $transport));
            }
            $retry = $this->definition($container, $retryReferences[$transport] ?? null);
            if ($retry?->getClass() !== MultiplierRetryStrategy::class) {
                throw new \LogicException(sprintf('Transport "%s" requires its bounded multiplier retry strategy.', $transport));
            }
            foreach (['max_retries', 'delay', 'multiplier', 'max_delay'] as $index => $key) {
                $value = $container->getParameterBag()->resolveValue($retry->getArgument($index));
                if (!is_numeric($value) || (float) $value !== (float) $policy[$key]) {
                    throw new \LogicException(sprintf('Transport "%s" retry policy differs for "%s".', $transport, $key));
                }
            }
        }
    }

    /** @return array<class-string, string> */
    private function codecClasses(ContainerBuilder $container): array
    {
        $types = [];
        $classes = [];
        foreach ($container->findTaggedServiceIds('baander.message_payload_codec') as $id => $tags) {
            $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass());
            if (!is_string($class) || !is_a($class, MessagePayloadCodecInterface::class, true)) {
                throw new \LogicException(sprintf('Payload codec "%s" must implement the payload codec contract.', $id));
            }
            foreach ($class::types() as $type => $messageClass) {
                if ($type === '' || !class_exists($messageClass)
                    || isset($types[$type]) || isset($classes[$messageClass])) {
                    throw new \LogicException(sprintf('Payload codec "%s" has a duplicate or invalid message registration.', $id));
                }
                $types[$type] = $messageClass;
                $classes[$messageClass] = $type;
            }
        }

        return $classes;
    }

    /** @param array<string, mixed> $handlers @param class-string $class */
    private function hasEligibleHandler(ContainerBuilder $container, array $handlers, string $class, string $transport): bool
    {
        $types = [$class, ...array_values(class_parents($class)), ...array_values(class_implements($class)), '*'];
        $namespace = $class;
        while (($position = strrpos($namespace, '\\')) !== false) {
            $namespace = substr($namespace, 0, $position);
            $types[] = $namespace . '\\*';
        }
        foreach ($types as $type) {
            $descriptors = $handlers[$type] ?? [];
            if ($descriptors instanceof ArgumentInterface) {
                $descriptors = $descriptors->getValues();
            }
            foreach ($descriptors as $descriptor) {
                $definition = $this->definition($container, $descriptor);
                if ($definition === null) {
                    continue;
                }
                $options = $definition->getArgument(1);
                if (!isset($options['from_transport']) || $options['from_transport'] === $transport) {
                    return true;
                }
            }
        }

        return false;
    }

    private function definition(ContainerBuilder $container, mixed $value): ?Definition
    {
        while ($value instanceof ArgumentInterface) {
            $value = $value->getValues()[0] ?? null;
        }
        if ($value instanceof Reference) {
            return $container->findDefinition((string) $value);
        }

        return $value instanceof Definition ? $value : null;
    }
}
