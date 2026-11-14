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
use App\Metadata\Interface\Request\ExtractMetadataRequest;
use App\Metadata\Interface\Request\MatchMetadataRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MetadataSyncControllerTest extends TestCase
{
    private const string ARTIST_ID = '4d1e2a3b-5c6f-4a7b-8c9d-0e1f2a3b4c5d';
    private const string OTHER_ARTIST_ID = '7f8e9d0c-1b2a-4c3d-9e4f-5a6b7c8d9e0f';

    private string $path;
    /** @var list<string> */
    private array $temporaryFiles = [];
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

        foreach ($this->temporaryFiles as $file) {
            unlink($file);
        }
    }

    public function testExtractAnswersTheMusicBrainzArtistIdOfAFileWithOneId(): void
    {
        $path = $this->mp3WithArtistIds(self::ARTIST_ID);

        $metadata = $this->extract($path);

        self::assertSame('Headhunterz', $metadata['artist']);
        self::assertSame(self::ARTIST_ID, $metadata['mbArtistId']);
    }

    public function testExtractLeavesTheMusicBrainzArtistIdEmptyForAFileWithTwoIds(): void
    {
        $path = $this->mp3WithArtistIds(self::ARTIST_ID . '/' . self::OTHER_ARTIST_ID);

        $metadata = $this->extract($path);

        self::assertSame('Headhunterz', $metadata['artist']);
        self::assertNull($metadata['mbArtistId']);
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

    /** @return array<string, mixed> */
    private function extract(string $path): array
    {
        $response = $this->controller->extract(new ExtractMetadataRequest($path));

        self::assertSame(200, $response->getStatusCode());

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['metadata'];
    }

    /** An ID3v2.3 file whose MusicBrainz artist ID frame is UTF-16, as Picard writes it. */
    private function mp3WithArtistIds(string $artistIds): string
    {
        $utf16 = static fn (string $text): string => "\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $frame = static fn (string $id, string $data): string => $id . pack('N', strlen($data)) . "\x00\x00" . $data;
        $frames = $frame('TPE1', "\x00Headhunterz\x00")
            . $frame('TXXX', "\x01" . $utf16('MusicBrainz Artist Id') . "\x00\x00" . $utf16($artistIds) . "\x00\x00");
        $size = strlen($frames);
        $header = "ID3\x03\x00\x00" . chr(($size >> 21) & 0x7F) . chr(($size >> 14) & 0x7F) . chr(($size >> 7) & 0x7F) . chr($size & 0x7F);

        $path = tempnam(sys_get_temp_dir(), 'baander-metadata-');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $header . $frames);

        return $path;
    }
}
