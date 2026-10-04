<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Interface\Controller;

use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\LastFm\LastFmAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Metadata\Interface\Controller\MetadataBrowseController;
use App\Metadata\Interface\Controller\MetadataSearchController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MetadataResponseTest extends TestCase
{
    public static string|false $response = '{}';

    protected function setUp(): void
    {
        require __DIR__ . '/Fixtures/http.php';
    }

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
        $controller = new MetadataSearchController(
            new MusicBrainzAdapter($logger, $encoder),
            new DiscogsAdapter('local-test-token', $logger, $encoder),
            new LastFmAdapter('local-test-token', $logger, $encoder),
        );

        $response = $controller->{$action}(new Request(['q' => 'track', 'source' => $source]));
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
        $controller = new MetadataBrowseController(
            new MusicBrainzAdapter($logger, $encoder),
            new LastFmAdapter('local-test-token', $logger, $encoder),
        );

        $response = $controller->releaseGroup('release-id');
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Album', $payload['data']['title']);
        self::assertSame('release-id', $payload['data']['id']);
    }
}
