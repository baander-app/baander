<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Interface\Controller;

use App\Tests\Fixtures\Metadata\ProviderSwitches;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Metadata\Infrastructure\Api\CoverArtArchive\CoverArtArchiveAdapter;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\LastFm\LastFmAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Metadata\Interface\Controller\MetadataBrowseController;
use App\Metadata\Interface\Controller\MetadataSearchController;
use App\Metadata\Interface\Request\SearchMetadataRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Baander\HttpClient\PublicHttpClient;
use Baander\HttpClient\PublicDestinationPolicy;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class MetadataResponseTest extends TestCase
{
    public static string|false $response = '{}';

    /** @return iterable<string, array{string, string}> */
    public static function searches(): iterable
    {
        foreach (['searchAlbum', 'searchSong'] as $action) {
            foreach (['musicbrainz', 'discogs'] as $source) {
                yield $action . ' ' . $source => [$action, $source];
            }
        }
    }

    #[DataProvider('searches')]
    public function testSearchSerializesTypedResult(string $action, string $source): void
    {
        self::$response = '{"count":7,"pagination":{"items":7},"results":[]}';
        $logger = new NullLogger();
        $encoder = new JsonEncoder();
        $httpClient = new PublicHttpClient(new MockHttpClient(static fn(): MockResponse => new MockResponse(self::$response === false ? '' : self::$response)), new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
        $controller = new MetadataSearchController(
            self::musicBrainz($logger, $encoder, $httpClient),
            new DiscogsAdapter('local-test-token', $logger, $encoder, $httpClient, ProviderSwitches::allEnabled()),
            new LastFmAdapter('local-test-token', $logger, $encoder, $httpClient, ProviderSwitches::allEnabled()),
            self::unlimited(),
            self::unlimited(),
        );

        $response = $controller->{$action}(self::member(), new SearchMetadataRequest(q: 'track', source: $source));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(7, $payload['data']['total']);
        self::assertSame([], $payload['data']['artists']);
    }

    public function testBrowseSerializesReleaseGroup(): void
    {
        self::$response = '{"id":"release-id","title":"Album"}';
        $logger = new NullLogger();
        $encoder = new JsonEncoder();
        $httpClient = new PublicHttpClient(new MockHttpClient(static fn(): MockResponse => new MockResponse(self::$response === false ? '' : self::$response)), new PublicDestinationPolicy(static fn(string $host): array => ['1.1.1.1']));
        $controller = new MetadataBrowseController(
            self::musicBrainz($logger, $encoder, $httpClient),
            new LastFmAdapter('local-test-token', $logger, $encoder, $httpClient, ProviderSwitches::allEnabled()),
            self::unlimited(),
        );

        $response = $controller->releaseGroup('release-id', self::member());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Album', $payload['data']['title']);
        self::assertSame('release-id', $payload['data']['id']);
    }

    private static function musicBrainz(NullLogger $logger, JsonEncoder $encoder, PublicHttpClient $httpClient): MusicBrainzAdapter
    {
        return new MusicBrainzAdapter($logger, $encoder, $httpClient, self::unlimited(), new CoverArtArchiveAdapter($logger, $encoder, $httpClient, ProviderSwitches::allEnabled()), ProviderSwitches::allEnabled());
    }

    private static function unlimited(): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'musicbrainz', 'policy' => 'no_limit'], new InMemoryStorage());
    }

    /** A signed-in member as the firewall supplies one. */
    private static function member(): SecurityUser
    {
        return new SecurityUser('0195c2a4-7b1e-7c3d-9f20-4a6b8c0d2e11', 'member@baander.app', 'stored-password-hash');
    }
}
