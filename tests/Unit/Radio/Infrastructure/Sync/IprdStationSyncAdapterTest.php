<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Infrastructure\Sync;

use App\Radio\Infrastructure\Sync\IprdStationSyncAdapter;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use Baander\HttpClient\ResponseTooLargeException;
use Baander\HttpClient\UnsafeDestinationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class IprdStationSyncAdapterTest extends TestCase
{
    /** @var list<string> the method and URL of every request sent */
    private array $requests = [];

    public function testSummaryCannotReachAnIpFromForbiddenDns(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');
        $adapter = new IprdStationSyncAdapter(new PublicHttpClient(
            $httpClient, new PublicDestinationPolicy(static fn(string $host): array => ['127.0.0.1']),
        ));
        $this->expectException(UnsafeDestinationException::class);
        $adapter->fetchCountries();
    }

    public function testCatalogRedirectCannotReachItsUnapprovedTarget(): void
    {
        $requests = 0;
        $raw = new \Symfony\Component\HttpClient\MockHttpClient(static function () use (&$requests): \Symfony\Component\HttpClient\Response\MockResponse {
            ++$requests;

            return new \Symfony\Component\HttpClient\Response\MockResponse('', [
                'http_code' => 302, 'response_headers' => ['Location: http://127.0.0.1/private'],
            ]);
        });
        $adapter = new IprdStationSyncAdapter(new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1'])));
        try {
            $adapter->fetchStationsByCountry('DE');
            self::fail('The catalog must not follow redirects.');
        } catch (UnsafeDestinationException) {
            self::assertSame(1, $requests);
        }
    }

    /** The catalog would list a German station, but it is padded past 64 MiB, so its body is never decoded. */
    public function testACatalogOverItsSizeCapFailsTheSync(): void
    {
        $padded = json_encode(self::CATALOG_DATA, JSON_THROW_ON_ERROR) . str_repeat(' ', 64 * 1024 * 1024);
        $adapter = new IprdStationSyncAdapter(new PublicHttpClient(
            new MockHttpClient(new MockResponse($padded)),
            new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']),
        ));

        $this->expectException(ResponseTooLargeException::class);
        $adapter->fetchStationsByCountry('DE');
    }

    private const CATALOG_DATA = [
        'version' => '1.0',
        'updated' => '2025-12-09T11:35:23Z',
        'stations' => [
            [
                'id' => 'de-radio-berlin-1',
                'name' => 'Radio Berlin',
                'country' => 'Germany',
                'language' => [],
                'genres' => ['pop'],
                'tags' => ['berlin'],
                'streams' => [['url' => 'https://stream.baander.app/rb', 'format' => 'mp3', 'bitrate' => 128, 'reliability' => 0.9]],
                'logo' => 'https://baander.app/logo.png',
                'website' => 'https://radioberlin.de',
            ],
            [
                'id' => 'fr-radio-paris-1',
                'name' => 'Radio Paris',
                'country' => 'France',
                'language' => [],
                'genres' => [],
                'tags' => [],
                'streams' => [],
                'logo' => null,
                'website' => null,
            ],
        ],
    ];

    public function testFetchCountriesParsesSummaryWithStaticNameMap(): void
    {
        $summaryResponse = $this->createResponse(200, [
            'total_stations' => 23088,
            'total_countries' => 228,
            'countries' => [
                ['code' => 'DE', 'count' => 2297],
                ['code' => 'FR', 'count' => 1534],
            ],
        ]);

        $adapter = $this->adapter($summaryResponse);
        $countries = $adapter->fetchCountries();

        $this->assertCount(2, $countries);
        $this->assertSame('DE', $countries[0]['code']);
        $this->assertSame('Germany', $countries[0]['name']);
        $this->assertSame(2297, $countries[0]['station_count']);
        $this->assertSame('FR', $countries[1]['code']);
        $this->assertSame('France', $countries[1]['name']);
        $this->assertSame(1534, $countries[1]['station_count']);
        // Only the summary is fetched to list countries, not the catalog.
        $this->assertSame(['GET https://iprd-org.github.io/iprd/site_data/summary.json'], $this->requests);
    }

    public function testFetchCountriesReturnsEmptyWhenNoCountriesKey(): void
    {
        $summaryResponse = $this->createResponse(200, ['total_stations' => 23088]);

        $adapter = $this->adapter($summaryResponse);
        $countries = $adapter->fetchCountries();

        $this->assertEmpty($countries);
    }

    public function testFetchCountriesFallsBackToCodeForUnknownCountry(): void
    {
        $summaryResponse = $this->createResponse(200, [
            'countries' => [
                ['code' => 'ZZ', 'count' => 5],
            ],
        ]);

        $adapter = $this->adapter($summaryResponse);
        $countries = $adapter->fetchCountries();

        $this->assertCount(1, $countries);
        $this->assertSame('ZZ', $countries[0]['code']);
        $this->assertSame('ZZ', $countries[0]['name']);
    }

    public function testFetchCountriesThrowsOnHttpError(): void
    {
        $errorResponse = $this->createResponse(503);

        $adapter = $this->adapter($errorResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');

        $adapter->fetchCountries();
    }

    public function testFetchStationsByCountryFiltersByCountryName(): void
    {
        $catalogResponse = $this->createResponse(200, self::CATALOG_DATA);

        $adapter = $this->adapter($catalogResponse);
        $stations = $adapter->fetchStationsByCountry('DE');

        $this->assertCount(1, $stations);
        $this->assertSame('de-radio-berlin-1', $stations[0]['external_id']);
        $this->assertSame('Radio Berlin', $stations[0]['name']);
        $this->assertSame('Germany', $stations[0]['country']);
        $this->assertCount(1, $stations[0]['streams']);
        $this->assertSame('https://stream.baander.app/rb', $stations[0]['streams'][0]['url']);
        $this->assertSame(['GET https://iprd-org.github.io/iprd/site_data/metadata/catalog.json'], $this->requests);
    }

    public function testFetchStationsByCountryReturnsEmptyForUnknownCode(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        // No HTTP calls expected — unknown code is rejected before fetching catalog
        $httpClient->expects($this->never())->method('request');

        $adapter = new IprdStationSyncAdapter(new PublicHttpClient($httpClient, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1'])));
        $stations = $adapter->fetchStationsByCountry('ZZ');

        $this->assertEmpty($stations);
    }

    public function testFetchStationsByCountryReturnsEmptyForNoMatches(): void
    {
        $catalogResponse = $this->createResponse(200, self::CATALOG_DATA);

        $adapter = $this->adapter($catalogResponse);
        $stations = $adapter->fetchStationsByCountry('JP');

        $this->assertEmpty($stations);
    }

    public function testFetchStationsByCountryThrowsOnHttpError(): void
    {
        $errorResponse = $this->createResponse(500);

        $adapter = $this->adapter($errorResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        $adapter->fetchStationsByCountry('DE');
    }

    public function testFetchStationsByCountryNormalizesLanguageArray(): void
    {
        $catalogResponse = $this->createResponse(200, [
            'version' => '1.0',
            'stations' => [
                [
                    'id' => 'de-multi-lang-1',
                    'name' => 'Multi Lang Radio',
                    'country' => 'Germany',
                    'language' => ['German', 'English'],
                    'streams' => [],
                ],
            ],
        ]);

        $adapter = $this->adapter($catalogResponse);
        $stations = $adapter->fetchStationsByCountry('DE');

        $this->assertCount(1, $stations);
        $this->assertSame('German, English', $stations[0]['language']);
    }

    /** @param array<array-key, mixed>|null $data */
    private function createResponse(int $statusCode, ?array $data = null): MockResponse
    {
        return new MockResponse($data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => $statusCode]);
    }

    private function adapter(MockResponse $response): IprdStationSyncAdapter
    {
        return new IprdStationSyncAdapter(new PublicHttpClient(
            new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
                $this->requests[] = $method . ' ' . $url;

                return $response;
            }),
            new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']),
        ));
    }
}
