<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimiter;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Builds the rate limiter catalog from the limiters FrameworkBundle registered
 * for framework.rate_limiter, so the configuration stays the only list of limiters.
 *
 * Fails the container build when a limiter does not keep its state in a cache
 * pool of its own: clearing that pool would otherwise reset other limiters too.
 */
final class RateLimiterCatalogPass implements CompilerPassInterface
{
    public const string DESCRIPTIONS_PARAMETER = 'app.rate_limiter.descriptions';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CachePoolRateLimiterAdministration::class)) {
            return;
        }

        /** @var array<string, string> $descriptions */
        $descriptions = $container->hasParameter(self::DESCRIPTIONS_PARAMETER)
            ? $container->getParameter(self::DESCRIPTIONS_PARAMETER)
            : [];

        $limiters = [];
        $pools = [];
        $poolOwners = [];
        foreach ($container->findTaggedServiceIds('rate_limiter') as $serviceId => $tags) {
            $name = (string) $tags[0]['name'];
            $limiter = $container->getDefinition($serviceId);
            $arguments = $limiter->getArguments();
            $storage = $arguments['index_1'] ?? $arguments[1] ?? null;
            $storageId = $storage instanceof Reference ? (string) $storage : null;

            if ($storageId === null || !$container->hasDefinition($storageId)
                || $container->getDefinition($storageId)->getClass() !== CacheStorage::class) {
                throw new LogicException(sprintf('Rate limiter "%s" must keep its state in a cache pool (framework.rate_limiter.%s.cache_pool) so it can be listed and cleared.', $name, $name));
            }

            $pool = (string) $container->getDefinition($storageId)->getArgument(0);
            if (isset($poolOwners[$pool])) {
                throw new LogicException(sprintf('Rate limiters "%s" and "%s" share cache pool "%s". Give each limiter its own pool so clearing one does not reset the other.', $poolOwners[$pool], $name, $pool));
            }
            $poolOwners[$pool] = $name;

            /** @var array{policy: string, limit?: int|string, interval?: string, rate?: array{interval?: string}} $config */
            $config = $container->getParameterBag()->resolveValue($limiter->getArgument(0));

            $limiters[$name] = [
                'policy' => $config['policy'],
                'limit' => (int) ($config['limit'] ?? 0),
                'interval' => $config['interval'] ?? $config['rate']['interval'] ?? null,
                'description' => $descriptions[$name] ?? null,
                'cachePool' => $pool,
            ];
            $pools[$name] = new Reference($pool);
        }

        $container->getDefinition(CachePoolRateLimiterAdministration::class)
            ->setArgument('$limiters', $limiters)
            ->setArgument('$pools', ServiceLocatorTagPass::register($container, $pools));
    }
}
