<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Binds the correct monolog logger channel to services based on their namespace.
 *
 * Only applies to services that have a LoggerInterface $logger constructor parameter.
 * Services in App\Auth\* receive @logger.auth, App\Catalog\* receive @logger.catalog, etc.
 */
final class BoundedContextLoggerPass implements CompilerPassInterface
{
    private const array NAMESPACE_CHANNEL_MAP = [
        'Activity' => 'activity',
        'Auth' => 'auth',
        'Catalog' => 'catalog',
        'Command' => 'command',
        'Discovery' => 'discovery',
        'Favorites' => 'favorites',
        'Filesystem' => 'filesystem',
        'Library' => 'library',
        'Lyrics' => 'lyrics',
        'Media' => 'media',
        'Metadata' => 'metadata',
        'Notification' => 'notification',
        'Party' => 'party',
        'Playlist' => 'playlist',
        'Radio' => 'radio',
        'Recommendation' => 'recommendation',
        'Session' => 'session',
        'Transcode' => 'transcode',
        'UserPreference' => 'user_preferences',
        'QoL' => 'qol',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();

            if ($class === null || !str_starts_with($class, 'App\\')) {
                continue;
            }

            if ($definition->isAbstract()) {
                continue;
            }

            try {
                if (!class_exists($class)) {
                    continue;
                }
            } catch (\Error) {
                continue;
            }

            $channel = $this->resolveChannel($class);
            if ($channel === null) {
                continue;
            }

            if (!$this->hasLoggerParameter($class)) {
                continue;
            }

            $definition->setBindings(array_merge(
                $definition->getBindings(),
                ['Psr\Log\LoggerInterface $logger' => new Reference("monolog.logger.{$channel}")]
            ));
        }
    }

    private function resolveChannel(string $class): ?string
    {
        return array_find(self::NAMESPACE_CHANNEL_MAP, fn($channel, $namespace) => str_starts_with($class, "App\\{$namespace}\\"));
    }

    private function hasLoggerParameter(string $class): bool
    {
        try {
            $constructor = new \ReflectionMethod($class, '__construct');
        } catch (\ReflectionException) {
            return false;
        }

        if (!$constructor->isPublic()) {
            return false;
        }

        foreach ($constructor->getParameters() as $param) {
            if ($param->getName() === 'logger') {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && is_a($type->getName(), LoggerInterface::class, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
