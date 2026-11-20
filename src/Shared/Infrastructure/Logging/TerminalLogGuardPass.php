<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Logger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final readonly class TerminalLogGuardPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!str_starts_with($id, 'monolog.logger.') && $id !== 'monolog.logger') {
                continue;
            }

            $loggerDefinition = $definition;
            while (null === $loggerDefinition->getClass() && $loggerDefinition instanceof ChildDefinition) {
                $loggerDefinition = $container->findDefinition($loggerDefinition->getParent());
            }
            $class = $container->getParameterBag()->resolveValue($loggerDefinition->getClass());

            if (is_string($class) && is_a($class, Logger::class, true)) {
                $definition->addTag('app.log_viewer.logger');
            }
        }
    }
}
