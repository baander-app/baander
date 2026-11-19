<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Port\ClientKeySetProviderInterface;
use App\Auth\Domain\Model\OAuth\Client;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Redis\RedisPoolExhaustedException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;
use RedisException;

/**
 * Serves a `private_key_jwt` client's signing keys from its inline JWK Set or from the
 * set it publishes at its `jwks_uri`.
 *
 * A fetched set is cached per key registration: the cache key includes the client's
 * `updated_at`, so replacing the keys makes the old entry unreachable, and a fetch that
 * was running during the replacement cannot bring the old keys back. Every fetch takes
 * a Redis lock first, so one fetch per client runs at a time and no fetch happens while
 * Redis is unavailable. An assertion naming a `kid` the cached set lacks fetches the set
 * again, at most once per client per refetch interval; within the interval the cached
 * set answers. A failed fetch caches nothing and leaves an earlier entry as it was.
 */
final readonly class ClientKeySetProvider implements ClientKeySetProviderInterface
{
    /** Outlasts the fetch budget, so a lock is never released by expiry while its fetch runs. */
    private const int FETCH_LOCK_SECONDS = ClientJwksFetcher::TIMEOUT_SECONDS + 2;

    public function __construct(
        private ClientJwksFetcher $fetcher,
        private CacheItemPoolInterface $cache,
        private RedisClientFactory $redisClientFactory,
        private int $cacheTtlSeconds = 3600,
        private int $refetchIntervalSeconds = 60,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function signingKeys(Client $client, ?string $kid): array
    {
        $source = $client->getKeySource();
        $jwksUri = $source?->jwksUri();
        $jwks = $jwksUri === null ? $source?->jwks() : $this->publishedSet($client, $jwksUri, $kid);

        return self::selectSigningKeys($jwks['keys'] ?? [], $kid);
    }

    /**
     * @return array{keys: list<array<string, mixed>>}|null
     *
     * @throws DpopStoreUnavailableException
     */
    private function publishedSet(Client $client, string $jwksUri, ?string $kid): ?array
    {
        $registration = self::registration($client);
        $item = $this->cache->getItem('oauth.client_jwks.' . $registration);
        $cached = $item->isHit() ? $item->get() : null;

        if (!is_array($cached) || !is_array($cached['keys'] ?? null)) {
            if (!$this->claim('oauth:client-jwks-fetch:' . $registration, self::FETCH_LOCK_SECONDS)) {
                // Another request is fetching this set right now.
                throw DpopStoreUnavailableException::clientKeySetFetch();
            }

            return $this->fetchInto($item, $jwksUri);
        }

        /** @var array{keys: list<array<string, mixed>>} $cached */
        if ($kid === null
            || self::selectSigningKeys($cached['keys'], $kid) !== []
            || !$this->claim('oauth:client-jwks-refetch:' . $registration, $this->refetchIntervalSeconds)) {
            return $cached;
        }

        return $this->fetchInto($item, $jwksUri);
    }

    /** @return array{keys: list<array<string, mixed>>}|null */
    private function fetchInto(CacheItemInterface $item, string $jwksUri): ?array
    {
        $jwks = $this->fetcher->fetch($jwksUri);
        if ($jwks !== null) {
            $this->cache->save($item->set($jwks)->expiresAfter($this->cacheTtlSeconds));
        }

        return $jwks;
    }

    /**
     * Takes a Redis key that expires after `$seconds`; false when someone else holds it.
     *
     * @throws DpopStoreUnavailableException when Redis does not answer
     */
    private function claim(string $key, int $seconds): bool
    {
        try {
            return $this->redisClientFactory->borrow(
                static fn (Redis $redis): bool => $redis->set($key, '1', ['NX', 'EX' => $seconds]) === true,
            );
        } catch (RedisException | RedisPoolExhaustedException $e) {
            $this->logger->warning('The client key set fetch lock is unavailable', ['exception' => $e]);

            throw DpopStoreUnavailableException::clientKeySetLock($e);
        }
    }

    /** A public client ID never holds a NUL byte, so the pair hashes without ambiguity. */
    private static function registration(Client $client): string
    {
        return hash('sha256', $client->getPublicId()->toString() . "\0" . $client->getUpdatedAt()->format('U.u'));
    }

    /**
     * @param list<array<string, mixed>> $keys
     * @return list<array<string, mixed>>
     */
    private static function selectSigningKeys(array $keys, ?string $kid): array
    {
        return array_values(array_filter(
            $keys,
            static fn (array $key): bool => ($key['use'] ?? 'sig') === 'sig'
                && ($kid === null || ($key['kid'] ?? null) === $kid),
        ));
    }
}
