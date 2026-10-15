<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api;

use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class DiscogsResponseHeadersTest extends TestCase
{
    public function testRateLimitRetryUsesTheCurrentResponseAndThenMapsSuccess(): void
    {
        $calls = 0;
        $raw = new MockHttpClient(static function () use (&$calls): MockResponse {
            return ++$calls === 1
                ? new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After: 0']])
                : new MockResponse('{"results":[{"id":1,"title":"Artist"}],"pagination":{"items":1}}');
        });
        $adapter = new DiscogsAdapter('private-token', new NullLogger(), new JsonEncoder(), $this->client($raw));
        self::assertSame('Artist', $adapter->searchArtist('Artist')->artists[0]->name);
        self::assertSame(2, $calls);
    }

    public function testRedirectIsNotAProviderRateLimitRetry(): void
    {
        $calls = 0;
        $raw = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://unapproved.baander.app/', 'Retry-After: 0']]);
        });
        $adapter = new DiscogsAdapter('private-token', new NullLogger(), new JsonEncoder(), $this->client($raw));
        self::assertSame([], $adapter->searchArtist('Artist')->artists);
        self::assertSame(1, $calls);
    }

    public function testTmdbRetryChecksTheSuccessfulSecondResponseStatus(): void
    {
        $calls = 0;
        $raw = new MockHttpClient(static function () use (&$calls): MockResponse {
            return ++$calls === 1
                ? new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['Retry-After: 0']])
                : new MockResponse('{"results":[{"id":1,"title":"Film"}],"total_results":1}');
        });
        $adapter = new TmdbAdapter('private-token', new NullLogger(), new JsonEncoder(), $this->client($raw));
        self::assertSame('Film', $adapter->searchMovie('Film')->first()?->title);
        self::assertSame(2, $calls);
    }

    private function client(MockHttpClient $raw): PublicHttpClient
    {
        return new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
    }
}
