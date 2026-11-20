<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Bridge\Symfony;

use Baander\RedisRateLimiter\Limiter\RedisRateLimiterFactory;
use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;
use Baander\RedisRateLimiter\Administration\RedisRateLimiterAdministration;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

/** Wires every configured limiter and its administration to the same atomic Redis store. */
final class RateLimiterCatalogPass implements CompilerPassInterface
{
    public const string DESCRIPTIONS_PARAMETER = 'baander.rate_limiter.descriptions';

    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, string> $descriptions */
        $descriptions = $container->hasParameter(self::DESCRIPTIONS_PARAMETER)
            ? $container->getParameter(self::DESCRIPTIONS_PARAMETER)
            : [];
        $limiters = [];
        foreach ($container->findTaggedServiceIds('rate_limiter') as $serviceId => $tags) {
            $name = (string) $tags[0]['name'];
            $limiter = $container->getDefinition($serviceId);
            $config = $container->getParameterBag()->resolveValue($limiter->getArgument(0));
            if (!is_array($config) || !in_array($config['policy'] ?? null, RedisRateLimiterFactory::POLICIES, true)) {
                throw new LogicException(sprintf('Rate limiter "%s" must use a fixed_window, sliding_window or token_bucket policy for atomic Redis storage.', $name));
            }
            if (isset($limiters[$name]) || ($config['id'] ?? null) !== $name) {
                throw new LogicException(sprintf('Rate limiter "%s" must have a unique matching configuration id.', $name));
            }
            /** @var array{id: string, policy: string, limit: int|string} $config */
            $limiters[$name] = [
                'policy' => $config['policy'],
                'limit' => (int) $config['limit'],
                'interval' => RedisRateLimiterFactory::interval($config),
                'description' => $descriptions[$name] ?? null,
                'storageNamespace' => RedisRateLimiterStore::namespace($name),
            ];
            $container->setDefinition($serviceId, (new Definition(RedisRateLimiterFactory::class, [
                $config,
                new Reference(RedisRateLimiterStore::class),
            ]))->setTags($limiter->getTags())->setPublic($limiter->isPublic()));
        }

        if ($container->hasDefinition(RedisRateLimiterAdministration::class)) {
            $container->getDefinition(RedisRateLimiterAdministration::class)
                ->setArgument('$limiters', $limiters);
        }
    }
}
