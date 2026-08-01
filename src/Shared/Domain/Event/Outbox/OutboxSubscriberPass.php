<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event\Outbox;

use App\Shared\Domain\Event\AbstractDomainEvent;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Discovers all AbstractDomainEvent subclasses in the container and registers
 * any subscriber whose __invoke accepts AbstractDomainEvent as a listener
 * for each concrete event class.
 *
 * This is necessary because Symfony's #[AsEventListener] resolves the event
 * name from the parameter type — so __invoke(AbstractDomainEvent $event)
 * registers for the "AbstractDomainEvent" event name, but dispatch() uses
 * the concrete class name. This pass bridges that gap.
 */
final class OutboxSubscriberPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $eventClasses = $this->discoverEventClasses($container);

        if (empty($eventClasses)) {
            return;
        }

        // Register any service whose __invoke accepts AbstractDomainEvent
        // for each discovered concrete event class.
        $subscriberIds = [
            OutboxSubscriber::class,
            'App\Shared\Infrastructure\Event\NotificationBridgeSubscriber',
        ];

        foreach ($subscriberIds as $subscriberId) {
            if (!$container->hasDefinition($subscriberId)) {
                continue;
            }

            $definition = $container->getDefinition($subscriberId);

            foreach ($eventClasses as $eventClass) {
                $definition->addTag('kernel.event_listener', [
                    'event' => $eventClass,
                    'method' => '__invoke',
                ]);
            }
        }
    }

    /**
     * @return list<class-string<AbstractDomainEvent>>
     */
    private function discoverEventClasses(ContainerBuilder $container): array
    {
        $eventClasses = [];

        // Scan source files for concrete domain-event classes. This avoids
        // depending on events being registered as DI service definitions.
        $sourceDir = $this->resolveSourceDir($container);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (!str_contains($path, '/Domain/Event/')) {
                continue;
            }

            $className = $this->extractClassName($path);
            if ($className === null) {
                continue;
            }

            if (class_exists($className) && is_subclass_of($className, AbstractDomainEvent::class)) {
                $eventClasses[$className] = true;
            }
        }

        // Also include subclasses that are already loaded (e.g. test-defined
        // events), so the pass does not silently ignore event classes that are
        // not service definitions.
        foreach (get_declared_classes() as $className) {
            if (is_subclass_of($className, AbstractDomainEvent::class)) {
                $eventClasses[$className] = true;
            }
        }

        return array_keys($eventClasses);
    }

    private function resolveSourceDir(ContainerBuilder $container): string
    {
        if ($container->hasParameter('kernel.project_dir')) {
            return $container->getParameter('kernel.project_dir') . '/src';
        }

        // Fallback for compiler-pass tests that do not populate kernel parameters.
        return dirname(__DIR__, 5) . '/src';
    }

    private function extractClassName(string $file): ?string
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        if (preg_match('/namespace\s+([^;]+);/', $contents, $nsMatch) !== 1) {
            return null;
        }

        if (preg_match('/\b(?:abstract\s+)?(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/', $contents, $classMatch) !== 1) {
            return null;
        }

        return $nsMatch[1] . '\\' . $classMatch[1];
    }
}
