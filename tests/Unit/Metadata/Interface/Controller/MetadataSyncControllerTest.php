<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Interface\Controller;

use App\Metadata\Infrastructure\Matching\MatchingStrategy;
use App\Metadata\Infrastructure\Matching\Validator\AlbumValidator;
use App\Metadata\Infrastructure\Matching\Validator\ArtistValidator;
use App\Metadata\Infrastructure\Matching\Validator\SongValidator;
use App\Metadata\Infrastructure\Reader\FlacReader;
use App\Metadata\Infrastructure\Reader\FormatDetector;
use App\Metadata\Infrastructure\Reader\Id3Reader;
use App\Metadata\Infrastructure\Reader\OggReader;
use App\Metadata\Interface\Controller\MetadataSyncController;
use App\Metadata\Interface\Request\MatchMetadataRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MetadataSyncControllerTest extends TestCase
{
    private string $path;
    private MetadataSyncController $controller;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-metadata-');
        self::assertNotFalse($path);
        $this->path = $path;

        $tag = 'TAG'
            . str_pad('Test Song', 30, "\x00")
            . str_pad('Test Artist', 30, "\x00")
            . str_pad('Test Album', 30, "\x00")
            . '2026'
            . str_repeat("\x00", 30)
            . "\xFF";
        file_put_contents($path, "\xFF\xFB" . str_repeat("\x00", 254) . $tag);

        $logger = new NullLogger();
        $this->controller = new MetadataSyncController(
            new FormatDetector(),
            new Id3Reader($logger),
            new FlacReader($logger),
            new OggReader($logger),
            new MatchingStrategy(new ArtistValidator(), new AlbumValidator(), new SongValidator()),
        );
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function testMatchMapsTheCandidateIdentityAndMetadata(): void
    {
        $candidate = [
            'source' => 'musicbrainz',
            'sourceId' => 'recording-42',
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
            'year' => 1995,
            'mbid' => 'recording-42',
            'internalField' => 'excluded',
        ];

        $response = $this->controller->match(new MatchMetadataRequest($this->path, [$candidate]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => [[
            'source' => 'musicbrainz',
            'sourceId' => 'recording-42',
            'confidence' => 1,
            'data' => [
                'title' => 'Test Song',
                'artist' => 'Test Artist',
                'album' => 'Test Album',
                'year' => 1995,
                'mbid' => 'recording-42',
            ],
        ]]], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testMatchLeavesAbsentCandidateFieldsNull(): void
    {
        $response = $this->controller->match(new MatchMetadataRequest($this->path, [['title' => 'Test Song']]));

        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertCount(1, $data);
        self::assertNull($data[0]['source']);
        self::assertNull($data[0]['sourceId']);
        self::assertSame([
            'title' => 'Test Song',
            'artist' => null,
            'album' => null,
            'year' => null,
            'mbid' => null,
        ], $data[0]['data']);
    }

    public function testMatchPreservesStrategyRankingAndFiltering(): void
    {
        $candidates = [
            ['title' => 'Test Song', 'artist' => 'Other Artist', 'album' => 'Other Album', 'sourceId' => 'partial'],
            ['title' => 'XYZ', 'artist' => 'ABC', 'album' => 'DEF', 'sourceId' => 'unrelated'],
            ['title' => 'Test Song', 'artist' => 'Test Artist', 'album' => 'Test Album', 'sourceId' => 'exact'],
        ];

        $response = $this->controller->match(new MatchMetadataRequest($this->path, $candidates));

        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
        self::assertSame(['exact', 'partial'], array_column($data, 'sourceId'));
        self::assertGreaterThan($data[1]['confidence'], $data[0]['confidence']);
    }
}
