<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Infrastructure\Sync;

use App\Radio\Infrastructure\Sync\IprdStationSyncAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class IprdStationSyncAdapterTest extends TestCase
{
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
                'streams' => [['url' => 'https://stream.example.com/rb', 'format' => 'mp3', 'bitrate' => 128, 'reliability' => 0.9]],
                'logo' => 'https://example.com/logo.png',
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

        // Only one HTTP call — the summary. No catalog fetch for country listing.
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('summary.json'))
            ->willReturn($summaryResponse);

        $adapter = new IprdStationSyncAdapter($httpClient);
        $countries = $adapter->fetchCountries();

        $this->assertCount(2, $countries);
        $this->assertSame('DE', $countries[0]['code']);
        $this->assertSame('Germany', $countries[0]['name']);
        $this->assertSame(2297, $countries[0]['station_count']);
        $this->assertSame('FR', $countries[1]['code']);
        $this->assertSame('France', $countries[1]['name']);
        $this->assertSame(1534, $countries[1]['station_count']);
    }

    public function testFetchCountriesReturnsEmptyWhenNoCountriesKey(): void
    {
        $summaryResponse = $this->createResponse(200, ['total_stations' => 23088]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($summaryResponse);

        $adapter = new IprdStationSyncAdapter($httpClient);
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

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($summaryResponse);

        $adapter = new IprdStationSyncAdapter($httpClient);
        $countries = $adapter->fetchCountries();

        $this->assertCount(1, $countries);
        $this->assertSame('ZZ', $countries[0]['code']);
        $this->assertSame('ZZ', $countries[0]['name']);
    }

    public function testFetchCountriesThrowsOnHttpError(): void
    {
        $errorResponse = $this->createResponse(503);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($errorResponse);

        $adapter = new IprdStationSyncAdapter($httpClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');

        $adapter->fetchCountries();
    }

    public function testFetchStationsByCountryFiltersByCountryName(): void
    {
        $catalogResponse = $this->createResponse(200, self::CATALOG_DATA);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('catalog.json'))
            ->willReturn($catalogResponse);

        $adapter = new IprdStationSyncAdapter($httpClient);
        $stations = $adapter->fetchStationsByCountry('DE');

        $this->assertCount(1, $stations);
        $this->assertSame('de-radio-berlin-1', $stations[0]['external_id']);
        $this->assertSame('Radio Berlin', $stations[0]['name']);
        $this->assertSame('Germany', $stations[0]['country']);
        $this->assertCount(1, $stations[0]['streams']);
        $this->assertSame('https://stream.example.com/rb', $stations[0]['streams'][0]['url']);
    }

    public function testFetchStationsByCountryReturnsEmptyForUnknownCode(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        // No HTTP calls expected — unknown code is rejected before fetching catalog
        $httpClient->expects($this->never())->method('request');

        $adapter = new IprdStationSyncAdapter($httpClient);
        $stations = $adapter->fetchStationsByCountry('ZZ');

        $this->assertEmpty($stations);
    }

    public function testFetchStationsByCountryReturnsEmptyForNoMatches(): void
    {
        $catalogResponse = $this->createResponse(200, self::CATALOG_DATA);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($catalogResponse);

        $adapter = new IprdStationSyncAdapter($httpClient);
        $stations = $adapter->fetchStationsByCountry('JP');

        $this->assertEmpty($stations);
    }

    public function testFetchStationsByCountryThrowsOnHttpError(): void
    {
        $errorResponse = $this->createResponse(500);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($errorResponse);

        $adapter = new IprdStationSyncAdapter($httpClient);

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

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($catalogResponse);

        $adapter = new IprdStationSyncAdapter($httpClient);
        $stations = $adapter->fetchStationsByCountry('DE');

        $this->assertCount(1, $stations);
        $this->assertSame('German, English', $stations[0]['language']);
    }

    private function createResponse(int $statusCode, ?array $data = null): ResponseInterface&MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);

        if ($data !== null) {
            $response->method('toArray')->willReturn($data);
        }

        return $response;
    }
}
