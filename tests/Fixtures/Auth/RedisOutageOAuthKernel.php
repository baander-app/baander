<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterFactory;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Grant-path OAuth kernel for the DPoP store outage tests.
 *
 * With a Redis DSN, every Redis client of the request path connects to it: the
 * RedisClientFactory behind the DPoP stores and atomic limiters, and the Symfony
 * connections behind Doctrine's caches, which the framework registers as
 * `.cache_connection.*` services. An unreachable DSN is the refused
 * outage. Without a DSN the kernel uses the configured Redis through a
 * BorrowLimitingRedisClientFactory, published as the REDIS service, so a test can
 * fail one chosen Redis call of a request.
 *
 * Explicit limiter isolation retains Symfony's policies in local memory so a
 * DPoP-specific outage test reaches the nonce/replay store even when Redis is paused.
 * Logout and revoke outage tests leave isolation disabled to exercise both stores.
 * Each variant needs its own directory with the signing keys in it.
 */
final class RedisOutageOAuthKernel extends GrantPathOAuthKernel
{
    public const string REDIS = 'oauth.outage.redis';

    /** @param string|null $redisDsn `redis://[user:pass@]host:port`, or null for the configured Redis with the counting pool */
    public function __construct(string $directory, string $databaseUrl, private readonly ?string $redisDsn = null, private readonly bool $isolateRateLimiters = false)
    {
        parent::__construct($directory, $databaseUrl);
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias(self::REDIS, RedisClientFactory::class)->setPublic(true);
        // Default priority runs after the framework's CachePoolPass (32), which registers the cache connections.
        $container->addCompilerPass(new class($this->redisDsn, $this->isolateRateLimiters) implements CompilerPassInterface {
            public function __construct(private readonly ?string $redisDsn, private readonly bool $isolateRateLimiters)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                if ($this->isolateRateLimiters) {
                    // These tests target DPoP failures, including a whole-server pause.
                    // Keep the configured policies while isolating their unrelated storage.
                    $container->setDefinition('oauth.outage.limiter_storage', new Definition(InMemoryStorage::class));
                    foreach ($container->getDefinitions() as $id => $definition) {
                        if (str_starts_with($id, 'limiter.') && $definition->getClass() === RedisRateLimiterFactory::class) {
                            $definition->setClass(RateLimiterFactory::class);
                            $definition->setArgument(1, new Reference('oauth.outage.limiter_storage'));
                        }
                    }
                }
                $factory = $container->getDefinition(RedisClientFactory::class);
                if ($this->redisDsn === null) {
                    $factory->setClass(BorrowLimitingRedisClientFactory::class);

                    return;
                }

                $factory->setArgument('$dsn', $this->redisDsn);
                $rewritten = 0;
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (!str_starts_with($id, '.cache_connection.')) {
                        continue;
                    }
                    // The same connect and read timeouts as config/packages/cache.yaml.
                    $timeout = (int) RedisClientFactory::TIMEOUT_SECONDS;
                    $definition->replaceArgument(0, $this->redisDsn . sprintf('?timeout=%d&read_timeout=%d', $timeout, $timeout));
                    $rewritten++;
                }
                if ($rewritten === 0) {
                    throw new \LogicException('No Symfony cache connection was found to point at the outage DSN.');
                }
            }
        });
    }
}
