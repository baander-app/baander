<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Mailer;

use ReflectionClass;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\CompileProcessor;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServicesPass;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mailer\Transport\NullTransportFactory;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class MailerProcessor implements CompileProcessor
{
    public function process(ContainerBuilder $container, Proxifier $proxifier): void
    {
        foreach (array_keys($container->findTaggedServiceIds('mailer.transport_factory')) as $factoryId) {
            $definition = $container->findDefinition($factoryId);
            $factoryClass = $definition->getClass();

            if ($factoryClass === NullTransportFactory::class || $definition->hasTag(ContainerConstants::TAG_UNMANAGED_FACTORY)) {
                continue;
            }

            $transportClass = $factoryClass !== null && str_ends_with($factoryClass, 'Factory')
                ? substr($factoryClass, 0, -strlen('Factory'))
                : null;

            if ($transportClass === null || !class_exists($transportClass) || !is_a($transportClass, TransportInterface::class, true)) {
                $this->logUnmanagedTransport($container, $factoryId, 'its concrete transport cannot be inferred from its factory class');

                continue;
            }

            $transport = new ReflectionClass($transportClass);
            if ($transport->isAbstract() || $transport->isFinal() || $transport->isReadOnly()) {
                $this->logUnmanagedTransport($container, $factoryId, 'its transport cannot be extended by the contextual proxy');

                continue;
            }

            // Factory outputs live inside shared Transports; make those outputs contextual individually.
            // Keep the SMTP session across borrows rather than resetting an established connection.
            $definition->addTag(ContainerConstants::TAG_UNMANAGED_FACTORY, [
                'factoryMethod' => 'create',
                'returnType' => $transportClass,
            ]);
        }
    }

    private function logUnmanagedTransport(ContainerBuilder $container, string $factoryId, string $reason): void
    {
        $container->log(new StatefulServicesPass(), sprintf(
            'Mailer factory "%s" remains shared because %s. Tag it "%s" with a concrete returnType to pool its outputs.',
            $factoryId,
            $reason,
            ContainerConstants::TAG_UNMANAGED_FACTORY,
        ));
    }
}
