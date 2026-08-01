<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Logger;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tags all Monolog logger channels with SwooleBundle's stateful service tag
 * so their handlers get reset on worker reloads.
 *
 * Monolog creates handler instances per channel, so we need to reset
 * at the logger level, not the handler service level.
 */
final readonly class MonologHandlerResetterPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!str_starts_with($id, 'monolog.logger.') && $id !== 'monolog.logger') {
                continue;
            }

            $class = $definition->getClass();

            // Tag all logger channels so MonologLoggerResetter resets their handlers
            if ($class === Logger::class || is_subclass_of($class, Logger::class)) {
                $definition->addTag(ContainerConstants::TAG_STATEFUL_SERVICE, [
                    'resetter' => MonologLoggerResetter::class,
                ]);
            }
        }
    }
}
