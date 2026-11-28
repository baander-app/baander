<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api\Tmdb;

use App\Tests\Fixtures\Metadata\ProviderSwitches;

use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

final class TmdbPersonLookupTest extends TestCase
{
    public function testImdbLookupAcceptsOnePersonAndEncodesTheExpectedSource(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertStringContainsString('/find/nm123?external_source=imdb_id', $url);

            return new MockResponse('{"person_results":[{"id":42}]}');
        });

        self::assertSame('42', $this->adapter($http)->findTmdbPersonId('nm123'));
    }

    public function testAmbiguousAndWrongPersonResponsesCannotLinkAnIdentity(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"person_results":[{"id":42},{"id":43}]}'),
            new MockResponse('{"id":43,"name":"Different person"}'),
        ]);
        $adapter = $this->adapter($http);

        self::assertNull($adapter->findTmdbPersonId('nm123'));
        self::assertNull($adapter->lookupPerson(42));
    }

    public function testMissingCredentialsAndInvalidImdbIdsMakeNoRequest(): void
    {
        $http = new MockHttpClient(static function (): never {
            self::fail('Unconfigured or invalid identity lookups must not make a request.');
        });

        self::assertNull($this->adapter($http, '')->lookupPerson(42));
        self::assertNull($this->adapter($http, '')->findTmdbPersonId('nm123'));
        self::assertNull($this->adapter($http)->findTmdbPersonId('tt123'));
    }

    public function testSuccessfulAndUnavailableBridgeLookupsAreCached(): void
    {
        foreach ([200, 503] as $status) {
            $calls = 0;
            $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($status, &$calls): MockResponse {
                ++$calls;
                self::assertLessThanOrEqual(5.0, $options['timeout']);
                self::assertLessThanOrEqual(5.0, $options['max_duration']);

                return new MockResponse('{"person_results":[{"id":42}]}', ['http_code' => $status]);
            });
            $adapter = $this->adapter($http, cache: new ArrayAdapter());
            foreach ([1, 2] as $lookup) {
                if ($status === 200) {
                    self::assertSame('42', $adapter->findTmdbPersonId('nm123'));
                    continue;
                }
                try {
                    $adapter->findTmdbPersonId('nm123');
                    self::fail('An unavailable bridge must remain retryable.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('temporarily unavailable', $error->getMessage(), 'lookup ' . $lookup);
                }
            }
            self::assertSame(1, $calls);
        }
    }

    public function testExcessiveRetryAfterCannotOccupyAProfileClaim(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            ++$calls;
            self::assertLessThanOrEqual(10.0, $options['timeout']);
            self::assertLessThanOrEqual(10.0, $options['max_duration']);

            return new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after: 86400']]);
        });

        try {
            $this->adapter($http)->lookupPerson(42);
            self::fail('A provider delay beyond the request bound must be retryable.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('bounded request window', $exception->getMessage());
        }
        self::assertSame(1, $calls);
    }

    private function adapter(MockHttpClient $http, string $token = 'fixture-token', ?CacheInterface $cache = null): TmdbAdapter
    {
        return new TmdbAdapter($token, new NullLogger(), new JsonEncoder(), new PublicHttpClient(
            $http,
            new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']),
        ), ProviderSwitches::allEnabled(), $cache);
    }
}
