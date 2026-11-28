<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Api;

use App\Metadata\Application\DTO\ImdbPersonLookupDisabled;
use App\Metadata\Application\Exception\MetadataProviderDisabled;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\LastFm\LastFmAdapter;
use App\Metadata\Infrastructure\Api\Spotify\SpotifyAdapter;
use App\Metadata\Infrastructure\Api\TasteDive\TasteDiveAdapter;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Tests\Fixtures\Metadata\ProviderSwitches;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class ProviderSwitchTest extends TestCase
{
    /** @return iterable<string, array{MetadataProvider, string, list<string|int>}> */
    public static function entryPoints(): iterable
    {
        yield 'Discogs artist search' => [MetadataProvider::Discogs, 'searchArtist', ['Artist']];
        yield 'Discogs release search' => [MetadataProvider::Discogs, 'searchRelease', ['Album']];
        yield 'Discogs master search' => [MetadataProvider::Discogs, 'searchMaster', ['Album']];
        yield 'Discogs artist lookup' => [MetadataProvider::Discogs, 'lookupArtist', [42]];
        yield 'Discogs release lookup' => [MetadataProvider::Discogs, 'lookupRelease', [42]];
        yield 'Last.fm artist information' => [MetadataProvider::LastFm, 'getArtistInfo', ['Artist']];
        yield 'Last.fm top tracks' => [MetadataProvider::LastFm, 'getArtistTopTracks', ['Artist']];
        yield 'Last.fm artist tags' => [MetadataProvider::LastFm, 'getArtistTags', ['Artist']];
        yield 'Last.fm artist search' => [MetadataProvider::LastFm, 'searchArtist', ['Artist']];
        yield 'Spotify artist search' => [MetadataProvider::Spotify, 'searchArtist', ['Artist']];
        yield 'Spotify album search' => [MetadataProvider::Spotify, 'searchAlbum', ['Album']];
        yield 'Spotify artist lookup' => [MetadataProvider::Spotify, 'getArtist', ['42']];
        yield 'Spotify artist albums' => [MetadataProvider::Spotify, 'getArtistAlbums', ['42']];
        yield 'TasteDive similar results' => [MetadataProvider::TasteDive, 'getSimilar', ['Artist']];
        yield 'TMDB movie search' => [MetadataProvider::Tmdb, 'searchMovie', ['Movie']];
        yield 'TMDB movie lookup' => [MetadataProvider::Tmdb, 'lookupMovie', [42]];
        yield 'TMDB person lookup' => [MetadataProvider::Tmdb, 'lookupPerson', [42]];
        yield 'TMDB series lookup' => [MetadataProvider::Tmdb, 'lookupTvSeries', [42]];
        yield 'TMDB episode lookup' => [MetadataProvider::Tmdb, 'lookupTvEpisode', [42, 1, 1]];
        yield 'TMDB collection lookup' => [MetadataProvider::Tmdb, 'lookupCollection', [42]];
        yield 'TMDB genres' => [MetadataProvider::Tmdb, 'getGenreList', []];
    }

    /** @param list<string|int> $arguments */
    #[DataProvider('entryPoints')]
    public function testDisabledProviderEntryPointMakesNoRequest(MetadataProvider $provider, string $method, array $arguments): void
    {
        $http = new MockHttpClient(static function (): never {
            self::fail('A disabled provider must make no HTTP request.');
        });
        $client = new PublicHttpClient($http, new PublicDestinationPolicy(static fn (string $host): array => ['1.1.1.1']));
        $logger = new NullLogger();
        $encoder = new JsonEncoder();
        $switches = ProviderSwitches::withDisabled($provider);
        $adapter = match ($provider) {
            MetadataProvider::Discogs => new DiscogsAdapter('fixture-token', $logger, $encoder, $client, $switches),
            MetadataProvider::LastFm => new LastFmAdapter('fixture-token', $logger, $encoder, $client, $switches),
            MetadataProvider::Spotify => new SpotifyAdapter('fixture-client', 'fixture-secret', $logger, $encoder, $client, $switches),
            MetadataProvider::TasteDive => new TasteDiveAdapter('fixture-token', $logger, $encoder, $client, $switches),
            MetadataProvider::Tmdb => new TmdbAdapter('fixture-token', $logger, $encoder, $client, $switches),
            default => throw new \LogicException('Provider has no test adapter.'),
        };
        try {
            $adapter->{$method}(...$arguments);
            self::fail('A disabled provider must give an explicit disabled outcome.');
        } catch (MetadataProviderDisabled $exception) {
            self::assertSame($provider, $exception->provider);
            self::assertSame('provider_disabled', $exception->details['reason']);
        }
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testDisablingTmdbBlocksTheCachedImdbBridgeAndKeepsItsConfiguredFlag(): void
    {
        $enabled = true;
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(static function () use (&$enabled): bool { return $enabled; });
        $http = new MockHttpClient([new MockResponse('{"person_results":[{"id":42}]}')]);
        $client = new PublicHttpClient($http, new PublicDestinationPolicy(static fn (string $host): array => ['1.1.1.1']));
        $adapter = new TmdbAdapter('fixture-token', new NullLogger(), new JsonEncoder(), $client, new MetadataProviderSwitches($settings), new ArrayAdapter());
        self::assertSame('42', $adapter->findTmdbPersonId('nm123'));
        $enabled = false;
        self::assertFalse($adapter->isEnabled());
        self::assertTrue($adapter->isConfigured());
        self::assertInstanceOf(ImdbPersonLookupDisabled::class, $adapter->findTmdbPersonId('nm123'));
        self::assertSame(1, $http->getRequestsCount());
        $enabled = true;
        self::assertSame('42', $adapter->findTmdbPersonId('nm123'));
        self::assertSame(1, $http->getRequestsCount());
    }
}
