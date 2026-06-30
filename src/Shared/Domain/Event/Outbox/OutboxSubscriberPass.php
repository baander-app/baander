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

        foreach ($container->getDefinitions() as $id => $def) {
            if (!class_exists((string) $id)) {
                continue;
            }

            if (is_subclass_of((string) $id, AbstractDomainEvent::class)) {
                $eventClasses[] = (string) $id;
            }
        }

        return $eventClasses;
    }
}
