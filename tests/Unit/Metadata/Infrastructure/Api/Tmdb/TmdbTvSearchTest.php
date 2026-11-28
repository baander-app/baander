<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api\Tmdb;

use App\Metadata\Application\Exception\MetadataProviderDisabled;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbHttpException;
use App\Tests\Fixtures\Metadata\ProviderSwitches;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class TmdbTvSearchTest extends TestCase
{
    public function testTvSearchRetriesOneRateLimitThenReturnsTheRecordedCandidates(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after: 0']]),
            new MockResponse('{"results":[{"id":10,"name":"Show","first_air_date":"2011-04-17"}]}'),
        ]);
        $result = $this->adapter($http)->searchTvSeries('Show', 2011);
        self::assertSame(10, $result['results'][0]['id']);
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testSecondRateLimitRemainsAProviderFailure(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after: 0']]),
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after: 0']]),
        ]);
        try {
            $this->adapter($http)->searchTvSeries('Show');
            self::fail('The provider retry must remain bounded.');
        } catch (TmdbHttpException $exception) {
            self::assertSame(429, $exception->status);
        }
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testDisabledTvLookupDoesNotContactTheProvider(): void
    {
        $http = new MockHttpClient(static function (): never { self::fail('A disabled provider must make no request.'); });
        $adapter = $this->adapter($http, false);
        $this->expectException(MetadataProviderDisabled::class);
        $adapter->lookupTvSeriesDetails(10);
    }

    public function testMissingVideoRecordRetainsItsHttpStatus(): void
    {
        $http = new MockHttpClient(new MockResponse('{"status_code":34}', ['http_code' => 404]));
        try {
            $this->adapter($http)->lookupTvSeriesDetails(10);
            self::fail('A missing record needs a distinct outcome.');
        } catch (TmdbHttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    private function adapter(MockHttpClient $http, bool $enabled = true): TmdbAdapter
    {
        $client = new PublicHttpClient($http, new PublicDestinationPolicy(static fn (string $host): array => ['1.1.1.1']));
        $switches = $enabled ? ProviderSwitches::allEnabled() : ProviderSwitches::withDisabled(MetadataProvider::Tmdb);

        return new TmdbAdapter('fixture-token', new NullLogger(), new JsonEncoder(), $client, $switches);
    }
}
