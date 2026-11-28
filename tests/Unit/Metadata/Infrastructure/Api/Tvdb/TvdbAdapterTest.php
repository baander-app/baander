<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api\Tvdb;

use App\Metadata\Application\Exception\MetadataProviderDisabled;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;
use App\Metadata\Infrastructure\Api\Tvdb\TvdbAdapter;
use App\Metadata\Infrastructure\Api\Tvdb\TvdbHttpException;
use App\Metadata\Infrastructure\Api\Tvdb\TvdbNotConfiguredException;
use App\Metadata\Infrastructure\Api\Tvdb\TvdbTransportException;
use App\Tests\Fixtures\Metadata\ProviderSwitches;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TvdbAdapterTest extends TestCase
{
    private const string KEY = 'fixture-licensed-key';

    public function testLoginSendsOnlyTheKeyAndSharesTheCachedTokenAcrossAdapters(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $url;
            self::assertLessThanOrEqual(15, $options['timeout']);
            self::assertLessThanOrEqual(15, $options['max_duration']);
            if (str_ends_with($url, '/login')) {
                self::assertSame('POST', $method);
                self::assertSame(['apikey' => self::KEY], json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR));

                return self::response(['token' => 'fixture-bearer-token']);
            }
            self::assertStringContainsString('Bearer fixture-bearer-token', json_encode($options['headers'], JSON_THROW_ON_ERROR));
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('series', $query['type']);
            self::assertSame('2011', $query['year']);

            return self::response([['tvdb_id' => '10', 'name' => 'Show']]);
        });
        $cache = new ArrayAdapter();
        self::assertCount(1, $this->adapter($http, cache: $cache)->searchSeries('Show', 2011));
        self::assertCount(1, $this->adapter($http, cache: $cache)->searchSeries('Show', 2011));
        self::assertCount(3, $requests);
        $keys = $cache->getValues() |> array_keys(...);
        self::assertStringNotContainsString(self::KEY, implode(' ', $keys));
    }

    public function testTokensExpireAfterTwentyFiveDaysAndKeysHaveSeparateCaches(): void
    {
        $clock = new MockClock('2026-11-28T12:00:00Z');
        $cache = new ArrayAdapter(clock: $clock);
        $logins = 0;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$logins): MockResponse {
            if (str_ends_with($url, '/login')) {
                ++$logins;
                return self::response(['token' => 'fixture-token-' . $logins]);
            }

            return self::response([]);
        });
        $first = $this->adapter($http, cache: $cache);
        $first->searchSeries('Show');
        $clock->sleep(25 * 86400 - 1);
        $first->searchSeries('Show');
        self::assertSame(1, $logins);
        $clock->sleep(2);
        $first->searchSeries('Show');
        self::assertSame(2, $logins);
        $this->adapter($http, key: 'different-fixture-key', cache: $cache)->searchSeries('Show');
        self::assertSame(3, $logins);
    }

    public function testUnauthorizedRequestRelogsOnceAndRetriesWithTheNewToken(): void
    {
        $responses = [
            self::response(['token' => 'fixture-first']),
            new MockResponse('{}', ['http_code' => 401]),
            self::response(['token' => 'fixture-second']),
            self::response(['id' => 10, 'name' => 'Show']),
        ];
        $calls = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$calls): MockResponse {
            ++$calls;
            if ($calls === 4) {
                self::assertStringContainsString('Bearer fixture-second', json_encode($options['headers'], JSON_THROW_ON_ERROR));
            }

            return array_shift($responses) ?? self::fail('The request exceeded its authentication retry.');
        });
        self::assertSame('Show', $this->adapter($http)->seriesExtended(10)['name']);
        self::assertSame(4, $http->getRequestsCount());
    }

    public function testRepeatedUnauthorizedResponseEndsTheRetry(): void
    {
        $http = new MockHttpClient([
            self::response(['token' => 'fixture-first']), new MockResponse('{}', ['http_code' => 401]),
            self::response(['token' => 'fixture-second']), new MockResponse('{}', ['http_code' => 401]),
        ]);
        try {
            $this->adapter($http)->seriesExtended(10);
            self::fail('A second unauthorized answer must stop the request.');
        } catch (TvdbHttpException $exception) {
            self::assertSame(401, $exception->status);
        }
        self::assertSame(4, $http->getRequestsCount());
    }

    public function testPersonExtendedRecordsAreCachedForOneDay(): void
    {
        $clock = new MockClock('2026-11-28T12:00:00Z');
        $cache = new ArrayAdapter(clock: $clock);
        $http = new MockHttpClient([
            self::response(['token' => 'fixture-token']),
            self::response(['id' => 42, 'name' => 'Person', 'remoteIds' => [['id' => 'nm123', 'sourceName' => 'fixture-source']]]),
            self::response(['id' => 42, 'name' => 'Updated person']),
        ]);
        $first = $this->adapter($http, cache: $cache);
        self::assertSame('Person', $first->personExtended(42)['name']);
        $clock->sleep(86400 - 1);
        self::assertSame('Person', $this->adapter($http, cache: $cache)->personExtended(42)['name']);
        self::assertSame(2, $http->getRequestsCount());
        $clock->sleep(2);
        self::assertSame('Updated person', $first->personExtended(42)['name']);
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testEpisodeDetailsAreSharedUntilTheirRevisionChangesOrExpires(): void
    {
        $clock = new MockClock('2026-11-28T12:00:00Z');
        $cache = new ArrayAdapter(clock: $clock);
        $first = ['id' => 42, 'lastUpdated' => '2026-11-27 12:00:00', 'characters' => [['peopleId' => 9]]];
        $updated = ['id' => 42, 'lastUpdated' => '2026-11-28 12:00:00', 'characters' => [['peopleId' => 10]]];
        $http = new MockHttpClient([
            self::response(['token' => 'fixture-token']),
            self::response($first),
            self::response($updated),
            self::response($updated),
        ]);
        $adapter = $this->adapter($http, cache: $cache);

        self::assertSame($first, $adapter->episodeExtended(42, $first['lastUpdated']));
        $secondAdapter = $this->adapter($http, cache: $cache);
        self::assertSame($first, $secondAdapter->episodeExtended(42, $first['lastUpdated']));
        self::assertSame(2, $http->getRequestsCount());

        self::assertSame($updated, $secondAdapter->episodeExtended(42, $updated['lastUpdated']));
        self::assertSame(3, $http->getRequestsCount());
        $clock->sleep(86401);
        self::assertSame($updated, $adapter->episodeExtended(42, $updated['lastUpdated']));
        self::assertSame(4, $http->getRequestsCount());
    }

    public function testEpisodeDetailsAreReadWithoutCacheWhenTheRevisionIsUnusable(): void
    {
        $http = new MockHttpClient(static fn (string $method): MockResponse => $method === 'POST'
            ? self::response(['token' => 'fixture-token'])
            : self::response(['id' => 42]));
        $adapter = $this->adapter($http);
        $revisions = [null, '', [], 0, '2026-02-30 12:00:00', 'yesterday'];
        foreach ($revisions as $revision) {
            $adapter->episodeExtended(42, $revision);
            $adapter->episodeExtended(42, $revision);
        }
        self::assertSame(1 + 2 * count($revisions), $http->getRequestsCount());
    }

    public function testEpisodeRetriesReuseEarlierSuccessesAndDiscardFailures(): void
    {
        $http = new MockHttpClient([
            self::response(['token' => 'fixture-token']),
            self::response(['id' => 42]),
            new MockResponse('{}', ['http_code' => 503]),
            self::response(['id' => 42]),
            self::response(['id' => 43]),
        ]);
        $adapter = $this->adapter($http);
        $revision = 1795867200;

        self::assertSame(['id' => 42], $adapter->episodeExtended(42, $revision));
        try {
            $adapter->episodeExtended(43, $revision);
            self::fail('A provider error must not return episode details.');
        } catch (TvdbHttpException $failure) {
            self::assertSame(503, $failure->status);
        }
        self::assertSame(['id' => 42], $adapter->episodeExtended(42, (string) $revision));
        self::assertSame(3, $http->getRequestsCount());
        try {
            $adapter->episodeExtended(43, $revision);
            self::fail('An extended response for another episode must be rejected.');
        } catch (TvdbTransportException) {
            self::assertSame(4, $http->getRequestsCount());
        }
        self::assertSame(['id' => 43], $adapter->episodeExtended(43, $revision));
        self::assertSame(5, $http->getRequestsCount());
    }

    public function testEpisodeDetailsFromAnotherRevisionDoNotReplaceTheListedVersion(): void
    {
        $http = new MockHttpClient([
            self::response(['token' => 'fixture-token']),
            self::response(['id' => 42, 'lastUpdated' => 1795867100]),
            self::response(['id' => 42, 'lastUpdated' => 1795867200]),
        ]);
        $adapter = $this->adapter($http);

        self::assertSame(1795867100, $adapter->episodeExtended(42, 1795867200)['lastUpdated']);
        self::assertSame(1795867200, $adapter->episodeExtended(42, 1795867200)['lastUpdated']);
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testDisabledAndEmptyKeyAdaptersMakeNoRequestIncludingCacheHits(): void
    {
        $http = new MockHttpClient(static function (): never { self::fail('No unusable adapter may contact the provider.'); });
        $adapter = $this->adapter($http, key: '');
        self::assertFalse($adapter->isConfigured());
        try {
            $adapter->searchSeries('Show');
            self::fail('The empty key has a distinct outcome.');
        } catch (TvdbNotConfiguredException) {
            self::assertSame(0, $http->getRequestsCount());
        }
        $disabled = $this->adapter($http, switches: ProviderSwitches::withDisabled(MetadataProvider::Tvdb));
        self::assertTrue($disabled->isConfigured());
        self::assertFalse($disabled->isEnabled());
        $this->expectException(MetadataProviderDisabled::class);
        $disabled->personExtended(42);
    }

    public function testDisablingTheProviderPreventsAFormerlyCachedPersonRead(): void
    {
        $cache = new ArrayAdapter();
        $http = new MockHttpClient([
            self::response(['token' => 'fixture-token']),
            self::response(['id' => 42, 'name' => 'Person']),
        ]);
        self::assertSame('Person', $this->adapter($http, cache: $cache)->personExtended(42)['name']);
        $disabled = $this->adapter($http, cache: $cache, switches: ProviderSwitches::withDisabled(MetadataProvider::Tvdb));
        try {
            $disabled->personExtended(42);
            self::fail('A cached person cannot bypass a switched-off provider.');
        } catch (MetadataProviderDisabled) {
            self::assertSame(2, $http->getRequestsCount());
        }
    }

    public function testDisablingTheProviderPreventsAFormerlyCachedEpisodeRead(): void
    {
        $cache = new ArrayAdapter();
        $http = new MockHttpClient([
            self::response(['token' => 'fixture-token']),
            self::response(['id' => 42]),
        ]);
        $revision = '2026-11-28 12:00:00';
        self::assertSame(['id' => 42], $this->adapter($http, cache: $cache)->episodeExtended(42, $revision));
        $disabled = $this->adapter($http, cache: $cache, switches: ProviderSwitches::withDisabled(MetadataProvider::Tvdb));
        $this->expectException(MetadataProviderDisabled::class);
        $disabled->episodeExtended(42, $revision);
    }

    public function testOneBoundedRateLimitRetryCanCompleteTheRequest(): void
    {
        $http = new MockHttpClient([
            self::response(['token' => 'fixture-token']),
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after: 0']]),
            self::response(['id' => 10, 'name' => 'Show']),
        ]);
        self::assertSame('Show', $this->adapter($http)->seriesExtended(10)['name']);
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testLongProviderDelayCannotOccupyTheRequestWindow(): void
    {
        $http = new MockHttpClient([
            self::response(['token' => 'fixture-token']),
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after: 3600']]),
        ]);
        try {
            $this->adapter($http)->seriesExtended(10);
            self::fail('An excessive provider wait must remain unavailable.');
        } catch (TvdbHttpException $exception) {
            self::assertSame(429, $exception->status);
        }
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testEpisodesFollowOnlyTheValidatedPageOnTheSameEndpoint(): void
    {
        $paths = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$paths): MockResponse {
            $paths[] = $url;
            if (str_ends_with($url, '/login')) {
                return self::response(['token' => 'fixture-token']);
            }
            if (str_ends_with($url, 'page=0')) {
                return self::response(['episodes' => [['id' => 1]]], ['next' => 'https://api4.thetvdb.com/v4/series/10/episodes/official?page=1']);
            }

            return self::response(['episodes' => [['id' => 2]]], ['next' => null]);
        });
        self::assertSame([1, 2], array_column($this->adapter($http)->seriesEpisodes(10, 'official'), 'id'));
        self::assertSame('https://api4.thetvdb.com/v4/series/10/episodes/official?page=1', $paths[2]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidNextLinks(): iterable
    {
        yield 'foreign host' => ['https://other.baander.app/v4/series/10/episodes/official?page=1'];
        yield 'private host' => ['https://127.0.0.1/v4/series/10/episodes/official?page=1'];
        yield 'downgrade' => ['http://api4.thetvdb.com/v4/series/10/episodes/official?page=1'];
        yield 'another endpoint' => ['https://api4.thetvdb.com/v4/people/42/extended?page=1'];
        yield 'user information' => ['https://fixture@api4.thetvdb.com/v4/series/10/episodes/official?page=1'];
        yield 'port' => ['https://api4.thetvdb.com:443/v4/series/10/episodes/official?page=1'];
        yield 'fragment' => ['?page=1#fragment'];
        yield 'additional query' => ['?page=1&apikey=fixture'];
        yield 'repeated page' => ['?page=0'];
        yield 'array page' => ['?page[]=1'];
        yield 'wrong type' => [1];
    }

    #[DataProvider('invalidNextLinks')]
    public function testInvalidPaginationIsRejectedBeforeAnyNextRequest(mixed $next): void
    {
        $http = new MockHttpClient([
            self::response(['token' => 'fixture-token']),
            self::response(['episodes' => [['id' => 1]]], ['next' => $next]),
        ]);
        try {
            $this->adapter($http)->seriesEpisodes(10, 'official');
            self::fail('An untrusted page link cannot select the next request.');
        } catch (TvdbTransportException) {
            self::assertSame(2, $http->getRequestsCount());
        }
    }

    public function testProviderErrorsKeepStatusAndDiscardEchoedCredentials(): void
    {
        foreach ([400, 401, 403, 404, 503] as $status) {
            $http = new MockHttpClient([
                self::response(['token' => 'fixture-token']),
                new MockResponse(self::KEY, ['http_code' => $status]),
                self::response(['token' => 'fixture-second']),
                new MockResponse(self::KEY, ['http_code' => $status]),
            ]);
            try {
                $this->adapter($http)->seriesExtended(10);
                self::fail('A failing provider status cannot become an empty result.');
            } catch (TvdbHttpException $exception) {
                self::assertSame($status, $exception->status);
                self::assertStringNotContainsString(self::KEY, $exception->getMessage());
                self::assertNull($exception->getPrevious());
            }
        }
    }

    public function testMalformedPayloadAndBodyFailureCannotExposePrivateDetails(): void
    {
        foreach (['{}', '{broken', '{"status":"success","data":{"id":11}}'] as $body) {
            $http = new MockHttpClient([self::response(['token' => 'fixture-token']), new MockResponse($body)]);
            try {
                $this->adapter($http)->seriesExtended(10);
                self::fail('Malformed or wrong-title data must remain unavailable.');
            } catch (TvdbTransportException $exception) {
                self::assertStringNotContainsString(self::KEY, $exception->getMessage());
            }
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, mixed>|null $links
     */
    private static function response(array $data, ?array $links = null): MockResponse
    {
        $response = ['status' => 'success', 'data' => $data];
        if ($links !== null) {
            $response['links'] = $links;
        }

        return new MockResponse(json_encode($response, JSON_THROW_ON_ERROR));
    }

    private function adapter(MockHttpClient $http, string $key = self::KEY, ?ArrayAdapter $cache = null, ?MetadataProviderSwitches $switches = null): TvdbAdapter
    {
        $client = new PublicHttpClient($http, new PublicDestinationPolicy(static fn (string $host): array => ['1.1.1.1']));

        return new TvdbAdapter($key, $client, $switches ?? ProviderSwitches::allEnabled(), $cache ?? new ArrayAdapter());
    }
}
