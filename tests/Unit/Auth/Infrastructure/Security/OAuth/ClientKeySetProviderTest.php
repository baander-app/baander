<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Security\OAuth;

use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ClientKeySource;
use App\Auth\Infrastructure\Security\OAuth\ClientJwksFetcher;
use App\Auth\Infrastructure\Security\OAuth\ClientKeySetProvider;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\Auth\SignedClientAssertion;
use Closure;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Key sets of `jwks_uri` clients: fetched through the guarded outbound client, cached
 * for an hour per key registration, and fetched again at most once a minute when an
 * assertion names an unknown `kid`.
 */
final class ClientKeySetProviderTest extends TestCase
{
    private const string JWKS_URI = 'https://keys.baander.app/jwks.json';

    private SignedClientAssertion $first;
    private SignedClientAssertion $second;
    private ArrayAdapter $cache;
    private MockClock $clock;

    /** @var list<string> URLs the transport was asked for */
    private array $requests = [];
    /** @var list<MockResponse> */
    private array $responses = [];
    /** @var array<string, string> Addresses by host */
    private array $dns = ['keys.baander.app' => '93.184.216.34'];
    /** @var array<string, array{ttl: int, expires: float}> Redis keys with their TTL and expiry */
    private array $redis = [];
    private ?RedisException $redisOutage = null;

    protected function setUp(): void
    {
        $this->first = new SignedClientAssertion('key-1');
        $this->second = new SignedClientAssertion('key-2');
        $this->clock = new MockClock();
        $this->cache = new ArrayAdapter(clock: $this->clock);
    }

    public function testAnInlineSetIsServedWithoutAFetch(): void
    {
        $client = Client::registerConfidentialWithKeys('Inline client', ['https://baander.app/callback'], ClientKeySource::fromJwks($this->first->jwks()));

        self::assertSame([$this->first->publicJwk()], $this->provider()->signingKeys($client, 'key-1'));
        self::assertSame([], $this->provider()->signingKeys($client, 'key-2'));
        self::assertSame([], $this->requests);
    }

    public function testAFetchedSetIsCachedAndServedWithoutAFetchWithinItsLifetime(): void
    {
        $this->publish($this->first);
        $client = $this->uriClient();
        $provider = $this->provider();

        $served = [$provider->signingKeys($client, 'key-1'), $provider->signingKeys($client, 'key-1'), $provider->signingKeys($client, null)];
        self::assertSame(array_fill(0, 3, [$this->first->publicJwk()]), $served);

        $this->clock->sleep(3599);
        self::assertSame([$this->first->publicJwk()], $provider->signingKeys($client, 'key-1'));
        self::assertSame([self::JWKS_URI], $this->requests);

        $this->publish($this->first);
        $this->clock->sleep(2);
        self::assertSame([$this->first->publicJwk()], $provider->signingKeys($client, 'key-1'));
        self::assertSame([self::JWKS_URI, self::JWKS_URI], $this->requests, 'An expired set is fetched again.');
    }

    /** A client rotates its key and signs with the new one, and no admin acts. */
    public function testAnUnknownKidRefetchesOnceAndFindsTheRotatedKey(): void
    {
        $this->publish($this->first);
        $this->publish($this->first, $this->second);
        $client = $this->uriClient();
        $provider = $this->provider();

        self::assertSame([$this->first->publicJwk()], $provider->signingKeys($client, 'key-1'));
        self::assertSame([$this->second->publicJwk()], $provider->signingKeys($client, 'key-2'));
        self::assertSame([$this->second->publicJwk()], $provider->signingKeys($client, 'key-2'), 'The refetched set replaces the cached one.');

        self::assertSame([self::JWKS_URI, self::JWKS_URI], $this->requests);
        $markers = array_filter($this->redis, static fn (string $key): bool => str_starts_with($key, 'oauth:client-jwks-refetch:'), ARRAY_FILTER_USE_KEY);
        self::assertSame([60], array_column($markers, 'ttl'));
    }

    public function testASecondUnknownKidWithinTheRefetchIntervalDoesNotFetch(): void
    {
        $this->publish($this->first);
        $this->publish($this->first);
        $client = $this->uriClient();
        $provider = $this->provider();

        $provider->signingKeys($client, 'key-1');
        self::assertSame([], $provider->signingKeys($client, 'key-2'));
        self::assertSame([], $provider->signingKeys($client, 'key-3'));

        self::assertSame([self::JWKS_URI, self::JWKS_URI], $this->requests);
    }

    public function testAKeyReplacementFetchesFreshKeysAtOnce(): void
    {
        $this->publish($this->first);
        $this->publish($this->second);
        $client = $this->uriClient();
        $provider = $this->provider();
        $provider->signingKeys($client, 'key-1');

        $client->replaceKeys(ClientKeySource::fromJwksUri('https://rotated.baander.app/jwks.json'));
        $this->dns['rotated.baander.app'] = '93.184.216.35';

        self::assertSame([$this->second->publicJwk()], $provider->signingKeys($client, 'key-2'));
        self::assertSame([self::JWKS_URI, 'https://rotated.baander.app/jwks.json'], $this->requests);
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function failedFetches(): iterable
    {
        yield 'server error' => [new MockResponse('{"keys":[]}', ['http_code' => 500])];
        yield 'redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: https://other.baander.app/jwks.json']])];
        yield 'timeout' => [new MockResponse(self::stalledBody())];
        yield 'transport error' => [new MockResponse('', ['error' => 'Connection reset by peer'])];
    }

    #[DataProvider('failedFetches')]
    public function testAFailedFetchIsAnOutageAndCachesNothing(MockResponse $response): void
    {
        $this->responses[] = $response;

        $this->assertOutage(fn (): array => $this->provider()->signingKeys($this->uriClient(), 'key-1'));

        self::assertCount(1, $this->requests);
        self::assertSame([], $this->cachedSets());
    }

    public function testAFailedRefetchIsAnOutageAndKeepsTheCachedSet(): void
    {
        $this->publish($this->first);
        $this->responses[] = new MockResponse('', ['http_code' => 503]);
        $client = $this->uriClient();
        $provider = $this->provider();
        $provider->signingKeys($client, 'key-1');

        $this->assertOutage(fn (): array => $provider->signingKeys($client, 'key-2'));
        self::assertSame([$this->first->publicJwk()], $provider->signingKeys($client, 'key-1'));
    }

    public function testABodyUpToTheCapIsReadAndALargerOneIsAFailedFetch(): void
    {
        $set = json_encode($this->first->jwks(), JSON_THROW_ON_ERROR);
        $this->responses[] = new MockResponse(str_pad($set, ClientKeySource::MAX_JWKS_BYTES));
        self::assertSame([$this->first->publicJwk()], $this->provider()->signingKeys($this->uriClient(), 'key-1'));

        $this->responses[] = new MockResponse([$set, str_repeat(' ', ClientKeySource::MAX_JWKS_BYTES - strlen($set)), ' ', str_repeat(' ', 4096)]);
        $this->assertOutage(fn (): array => $this->provider()->signingKeys($this->uriClient(), 'key-1'));
        self::assertCount(1, $this->cachedSets());
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedDestinations(): iterable
    {
        yield 'loopback address' => ['https://keys.baander.app/jwks.json', '127.0.0.1'];
        yield 'private address' => ['https://keys.baander.app/jwks.json', '10.0.0.7'];
        yield 'mapped loopback address' => ['https://keys.baander.app/jwks.json', '::ffff:127.0.0.1'];
    }

    #[DataProvider('refusedDestinations')]
    public function testANonPublicDestinationIsRefusedBeforeAnyRequest(string $uri, string $address): void
    {
        $this->dns['keys.baander.app'] = $address;
        $client = Client::registerConfidentialWithKeys('Key client', ['https://baander.app/callback'], ClientKeySource::fromJwksUri($uri));

        $this->assertOutage(fn (): array => $this->provider()->signingKeys($client, 'key-1'));

        self::assertSame([], $this->requests);
    }

    public function testTheAllowlistIsTheUrisOwnHostInAnyCase(): void
    {
        $this->publish($this->first);
        $client = Client::registerConfidentialWithKeys('Key client', ['https://baander.app/callback'], ClientKeySource::fromJwksUri('https://Keys.Baander.App/jwks.json'));

        self::assertSame([$this->first->publicJwk()], $this->provider()->signingKeys($client, 'key-1'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedSets(): iterable
    {
        yield 'private key member' => ['{"keys":[{"kty":"EC","crv":"P-256","x":"AA","y":"AA","d":"AA","kid":"key-1"}]}'];
        yield 'symmetric key' => ['{"keys":[{"kty":"oct","k":"AA","kid":"key-1"}]}'];
        yield 'RSA key over 4096 bits' => ['{"keys":[{"kty":"RSA","n":"' . str_repeat('_', 1366) . '","e":"AQAB","kid":"key-1"}]}'];
        yield 'RSA key with exponent 3' => ['{"keys":[{"kty":"RSA","n":"' . str_repeat('_', 342) . '","e":"Aw","kid":"key-1"}]}'];
        yield 'not JSON' => ['<html>keys</html>'];
        yield 'no keys' => ['{"keys":[]}'];
    }

    #[DataProvider('malformedSets')]
    public function testAMalformedSetOffersNoKeyAndIsNotCached(string $body): void
    {
        $this->responses[] = new MockResponse($body);

        self::assertSame([], $this->provider()->signingKeys($this->uriClient(), 'key-1'));
        self::assertSame([], $this->cachedSets());
    }

    public function testWithRedisUnavailableNoKeySetIsFetched(): void
    {
        $this->publish($this->first);
        $this->redisOutage = new RedisException('Connection refused');

        $this->assertOutage(fn (): array => $this->provider()->signingKeys($this->uriClient(), 'key-1'));

        self::assertSame([], $this->requests);
    }

    public function testWhileAnotherRequestFetchesTheSetTheClientIsAskedToRetry(): void
    {
        $this->publish($this->first);
        $client = $this->uriClient();
        $this->provider()->signingKeys($client, 'key-1');
        $this->cache->clear();

        $this->assertOutage(fn (): array => $this->provider()->signingKeys($client, 'key-1'));

        self::assertCount(1, $this->requests);
    }

    private function provider(): ClientKeySetProvider
    {
        $transport = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(0, $options['max_redirects']);
            self::assertLessThanOrEqual(3.0, $options['max_duration']);
            $this->requests[] = $url;

            return array_shift($this->responses) ?? self::fail('No response is left for ' . $url);
        });
        $policy = new PublicDestinationPolicy(fn (string $host): array => [$this->dns[$host] ?? self::fail('No address for ' . $host)]);

        return new ClientKeySetProvider(
            new ClientJwksFetcher(new PublicHttpClient($transport, $policy)),
            $this->cache,
            $this->redisFactory(),
            cacheTtlSeconds: 3600,
            refetchIntervalSeconds: 60,
        );
    }

    private function redisFactory(): RedisClientFactory
    {
        $store = &$this->redis;
        $clock = $this->clock;
        $redis = $this->createStub(Redis::class);
        $redis->method('set')->willReturnCallback(static function (string $key, mixed $value, mixed $options) use (&$store, $clock): bool {
            self::assertIsArray($options);
            self::assertContains('NX', $options);
            self::assertIsInt($options['EX'] ?? null);
            $now = (float) $clock->now()->format('U.u');
            if (($store[$key]['expires'] ?? 0.0) > $now) {
                return false;
            }
            $store[$key] = ['ttl' => $options['EX'], 'expires' => $now + $options['EX']];

            return true;
        });

        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(fn (Closure $callback): mixed => $this->redisOutage === null
            ? $callback($redis)
            : throw $this->redisOutage);

        return $factory;
    }

    private function uriClient(): Client
    {
        return Client::registerConfidentialWithKeys('Key client', ['https://baander.app/callback'], ClientKeySource::fromJwksUri(self::JWKS_URI));
    }

    private function publish(SignedClientAssertion ...$keys): void
    {
        $this->responses[] = new MockResponse(json_encode(['keys' => array_map(static fn (SignedClientAssertion $key): array => $key->publicJwk(), $keys)], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> The cached entries; the array adapter also lists keys it missed, as null */
    private function cachedSets(): array
    {
        return array_filter($this->cache->getValues(), static fn (mixed $value): bool => $value !== null);
    }

    private static function stalledBody(): Generator
    {
        yield '';
    }

    private function assertOutage(Closure $fetch): void
    {
        try {
            $fetch();
            self::fail('The key set should be temporarily unavailable.');
        } catch (DpopStoreUnavailableException) {
            $this->addToAssertionCount(1);
        }
    }
}
