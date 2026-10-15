<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api;

use App\Metadata\Infrastructure\Api\CoverArtArchive\CoverArtArchiveAdapter;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\LastFm\LastFmAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Metadata\Infrastructure\Api\Spotify\SpotifyAdapter;
use App\Metadata\Infrastructure\Api\TasteDive\TasteDiveAdapter;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class ProviderDestinationAdapterTest extends TestCase
{
    private const string SECRET = 'private-provider-credential-canary';

    /** @return iterable<string, array{string}> */
    public static function providers(): iterable
    {
        foreach (['musicbrainz', 'discogs', 'lastfm', 'spotify', 'tastedive', 'tmdb', 'coverartarchive'] as $provider) {
            yield $provider => [$provider];
        }
    }

    #[DataProvider('providers')]
    public function testProviderMappingUsesValidatedPinnedRequests(string $provider): void
    {
        $requests = [];
        $raw = new MockHttpClient(function (string $method, string $url, array $options) use ($provider, &$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            self::assertSame('https', parse_url($url, PHP_URL_SCHEME));
            self::assertSame([(string) parse_url($url, PHP_URL_HOST) => '1.1.1.1'], $options['resolve']);
            self::assertSame(0, $options['max_redirects']);
            self::assertTrue($options['verify_peer']);
            self::assertTrue($options['verify_host']);
            if (parse_url($url, PHP_URL_HOST) === 'accounts.spotify.com') {
                self::assertSame('POST', $method);
                self::assertSame('grant_type=client_credentials', $options['body']);
                self::assertStringContainsString(base64_encode('client:' . self::SECRET), json_encode($options['headers'], JSON_THROW_ON_ERROR));

                return new MockResponse('{"access_token":"spotify-access-canary","expires_in":3600}');
            }

            return new MockResponse(json_encode($this->successBody($provider), JSON_THROW_ON_ERROR));
        });
        $client = new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
        self::assertSame($provider === 'coverartarchive' ? 'https://images.baander.app/cover.jpg' : 'Artist', $this->invokeProvider($provider, $client));
        self::assertCount($provider === 'spotify' ? 2 : 1, $requests);
        if ($provider === 'spotify') {
            self::assertStringContainsString('spotify-access-canary', json_encode($requests[1]['options']['headers'], JSON_THROW_ON_ERROR));
        }
    }

    #[DataProvider('providers')]
    public function testPrivateDnsCannotReachTheUnderlyingTransportOrExposeCredentials(string $provider): void
    {
        $handler = new TestHandler();
        $logger = new Logger('provider', [$handler]);
        $requests = 0;
        $raw = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}');
        });
        $client = new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['127.0.0.1']));
        if ($provider === 'spotify') {
            try {
                $this->invokeProvider($provider, $client, $logger);
                self::fail('Spotify authentication must report its failed token acquisition.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Failed to authenticate with Spotify API.', $exception->getMessage());
            }
        } else {
            self::assertNull($this->invokeProvider($provider, $client, $logger));
        }
        self::assertSame(0, $requests);
        self::assertStringNotContainsString(self::SECRET, json_encode($handler->getRecords(), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('providers')]
    public function testBodyTransportFailuresDoNotLeakExceptionDetailsOrTriggerRetries(string $provider): void
    {
        $handler = new TestHandler();
        $logger = new Logger('provider', [$handler]);
        $requests = 0;
        $raw = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;
            $body = (static function (): \Generator {
                yield new \RuntimeException('Private transport error ' . self::SECRET);
            })();

            return new MockResponse($body);
        });
        $client = new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
        try {
            self::assertNull($this->invokeProvider($provider, $client, $logger));
        } catch (\RuntimeException $exception) {
            self::assertSame('spotify', $provider);
            self::assertSame('Failed to authenticate with Spotify API.', $exception->getMessage());
        }
        self::assertSame(1, $requests);
        self::assertStringNotContainsString(self::SECRET, json_encode($handler->getRecords(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Private transport error', json_encode($handler->getRecords(), JSON_THROW_ON_ERROR));
    }

    public function testCoverArtArchiveFollowsItsApprovedArchiveRedirect(): void
    {
        $hosts = [];
        $raw = new MockHttpClient(static function (string $method, string $url) use (&$hosts): MockResponse {
            $hosts[] = parse_url($url, PHP_URL_HOST);

            return count($hosts) === 1
                ? new MockResponse('', ['http_code' => 307, 'response_headers' => ['Location: https://archive.org/download/release/index.json']])
                : new MockResponse('[{"image":"https://images.baander.app/cover.jpg"}]');
        });
        $client = new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
        self::assertSame('https://images.baander.app/cover.jpg', $this->invokeProvider('coverartarchive', $client));
        self::assertSame(['coverartarchive.org', 'archive.org'], $hosts);
    }

    public function testQueryCredentialsAndProviderErrorEchoNeverEnterLogs(): void
    {
        foreach (['lastfm', 'tastedive'] as $provider) {
            $handler = new TestHandler();
            $raw = new MockHttpClient(new MockResponse(json_encode(['error' => 6, 'message' => self::SECRET], JSON_THROW_ON_ERROR)));
            $client = new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
            self::assertNull($this->invokeProvider($provider, $client, new Logger('provider', [$handler])));
            self::assertNotEmpty($handler->getRecords());
            self::assertStringNotContainsString(self::SECRET, json_encode($handler->getRecords(), JSON_THROW_ON_ERROR));
        }
    }

    public function testSpotifyTokenRedirectIsNeverFollowedOrRetried(): void
    {
        $calls = 0;
        $raw = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 307, 'response_headers' => ['Location: https://api.spotify.com/token']]);
        });
        $client = new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
        try {
            $this->invokeProvider('spotify', $client);
            self::fail('A redirected token POST must not authenticate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Failed to authenticate with Spotify API.', $exception->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testSpotifyApiRateRetryRetainsTheCachedTokenAndUsesTheCurrentResponse(): void
    {
        $calls = [];
        $raw = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $method . ' ' . parse_url($url, PHP_URL_HOST);
            if (count($calls) === 1) {
                return new MockResponse('{"access_token":"spotify-access-canary","expires_in":3600}');
            }

            return count($calls) === 2
                ? new MockResponse('{"error":{"status":429}}', ['http_code' => 429, 'response_headers' => ['Retry-After: 0']])
                : new MockResponse('{"artists":{"items":[{"id":"artist","name":"Artist"}]}}');
        });
        $client = new PublicHttpClient($raw, new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
        self::assertSame('Artist', $this->invokeProvider('spotify', $client));
        self::assertSame(['POST accounts.spotify.com', 'GET api.spotify.com', 'GET api.spotify.com'], $calls);
    }

    /** @return array<array-key, mixed> */
    private function successBody(string $provider): array
    {
        return match ($provider) {
            'musicbrainz' => ['count' => 1, 'artists' => [['id' => 'artist', 'name' => 'Artist']]],
            'discogs' => ['pagination' => ['items' => 1], 'results' => [['id' => 1, 'title' => 'Artist']]],
            'lastfm' => ['artist' => ['name' => 'Artist']],
            'spotify' => ['artists' => ['items' => [['id' => 'artist', 'name' => 'Artist']]]],
            'tastedive' => ['Similar' => ['Results' => [['Name' => 'Artist']]]],
            'tmdb' => ['total_results' => 1, 'results' => [['id' => 1, 'title' => 'Artist']]],
            'coverartarchive' => [['image' => 'https://images.baander.app/cover.jpg']],
            default => throw new \LogicException('Unknown fixture provider.'),
        };
    }

    private function invokeProvider(string $provider, PublicHttpClient $client, ?LoggerInterface $logger = null): ?string
    {
        $logger ??= new NullLogger();
        $encoder = new JsonEncoder();

        return match ($provider) {
            'musicbrainz' => (new MusicBrainzAdapter($logger, $encoder, $client))->searchArtist('Artist')->artists[0]->name ?? null,
            'discogs' => (new DiscogsAdapter(self::SECRET, $logger, $encoder, $client))->searchArtist('Artist')->artists[0]->name ?? null,
            'lastfm' => (new LastFmAdapter(self::SECRET, $logger, $encoder, $client))->getArtistInfo('Artist')['name'] ?? null,
            'spotify' => (new SpotifyAdapter('client', self::SECRET, $logger, $encoder, $client))->searchArtist('Artist')[0]['name'] ?? null,
            'tastedive' => (new TasteDiveAdapter(self::SECRET, $logger, $encoder, $client))->getSimilar('Artist')[0]['name'] ?? null,
            'tmdb' => (new TmdbAdapter(self::SECRET, $logger, $encoder, $client))->searchMovie('Artist')->first()?->title,
            'coverartarchive' => (new CoverArtArchiveAdapter($logger, $encoder, $client))->getReleaseCover('release')[0]['image'] ?? null,
            default => throw new \LogicException('Unknown fixture provider.'),
        };
    }
}
