<?php

declare(strict_types=1);

namespace Baander\DevQualification\Tests;

use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzArtist;
use App\Metadata\Application\DTO\MusicBrainz\MusicBrainzRelease;
use App\Metadata\Infrastructure\Api\CoverArtArchive\CoverArtArchiveAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use Baander\DevQualification\Fixtures\Qualification\Dataset;
use Baander\DevQualification\Fixtures\Qualification\MetadataTransport;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use Baander\HttpClient\UnsafeDestinationException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * The tests that drive the application's PublicHttpClient require the curl extension: the client
 * passes curl options, and the application image always loads it.
 */
final class QualificationMetadataTransportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qualification-metadata-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/manifest.json', json_encode(Dataset::plan('metadata-test'), JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    #[RequiresPhpExtension('curl')]
    public function testRealPublicPolicyRetainsHostValidationAndTransportReturnsOwnedMetadata(): void
    {
        $plan = Dataset::plan('metadata-test');
        $song = $plan['files'][0];
        $transport = MetadataTransport::create($this->directory . '/manifest.json', $this->directory . '/audit.jsonl');
        $client = new PublicHttpClient($transport, new PublicDestinationPolicy(MetadataTransport::dnsResolver()));
        $url = 'https://musicbrainz.org/ws/2/recording?' . http_build_query(['query' => $song['title'], 'limit' => 5, 'fmt' => 'json']);
        $body = $client->request('GET', $url, ['musicbrainz.org'])->toArray();
        self::assertSame($song['metadata']['id'], $body['recordings'][0]['id']);
        self::assertSame(100, $body['recordings'][0]['score']);
        self::assertSame('matched', json_decode(trim(file_get_contents($this->directory . '/audit.jsonl')), true, flags: JSON_THROW_ON_ERROR)['outcome']);
        $this->expectException(UnsafeDestinationException::class);
        $client->request('GET', 'https://127.0.0.1/ws/2/recording', ['musicbrainz.org']);
    }

    public function testUnknownQueryIsRejectedAndAuditedWithoutLeakingHeaders(): void
    {
        $transport = MetadataTransport::create($this->directory . '/manifest.json', $this->directory . '/audit.jsonl');
        try {
            $transport->request('GET', 'https://musicbrainz.org/ws/2/recording?query=unknown&fmt=json', ['headers' => ['Authorization' => 'secret-canary']])->getContent();
            self::fail('Unknown fixture query succeeded.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('Unmatched qualification metadata request', $error->getMessage());
        }
        $audit = file_get_contents($this->directory . '/audit.jsonl');
        self::assertStringNotContainsString('secret-canary', $audit);
        self::assertStringContainsString('unmatched', $audit);
    }

    public function testKnownTitleDoesNotAuthorizeAnUnknownQueryShape(): void
    {
        $plan = Dataset::plan('metadata-test');
        $transport = MetadataTransport::create($this->directory . '/manifest.json', $this->directory . '/audit.jsonl');
        $url = 'https://musicbrainz.org/ws/2/recording?' . http_build_query(['query' => $plan['files'][0]['title'], 'limit' => 0, 'fmt' => 'json']);
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Unmatched qualification metadata request.');
        $transport->request('GET', $url)->getContent();
    }

    #[RequiresPhpExtension('curl')]
    public function testRealAdaptersAcceptTheRecordedSearchAndLookupContracts(): void
    {
        $plan = Dataset::plan('metadata-test');
        $client = new PublicHttpClient(MetadataTransport::create($this->directory . '/manifest.json', $this->directory . '/audit.jsonl'), new PublicDestinationPolicy(MetadataTransport::dnsResolver()));
        $logger = new NullLogger();
        $encoder = new JsonEncoder();
        $music = new MusicBrainzAdapter(
            $logger,
            $encoder,
            $client,
            new RateLimiterFactory(['id' => 'musicbrainz', 'policy' => 'no_limit'], new InMemoryStorage()),
            new CoverArtArchiveAdapter($logger, $encoder, $client),
        );
        $song = $plan['files'][0];
        self::assertSame($song['metadata']['id'], $music->searchRecording($song['title'], limit: 5)->recordings[0]->id);
        self::assertSame($plan['albums'][$song['album']], $music->searchReleaseGroup($song['album'], limit: 5)->releaseGroups[0]->id);
        self::assertSame($plan['artists'][$song['artist']], $music->searchArtist($song['artist'], limit: 5)->artists[0]->id);
        $tmdb = new TmdbAdapter('fixture-token', $logger, $encoder, $client);
        $movie = $plan['files'][2000];
        self::assertSame($movie['metadata']['tmdbId'], $tmdb->searchMovie($movie['title'], limit: 1)->first()->id);
        self::assertSame($movie['title'], $tmdb->lookupMovie($movie['metadata']['tmdbId'])->title);
    }

    #[RequiresPhpExtension('curl')]
    public function testReleaseSearchAnswersTheOneReleaseOfAnAlbum(): void
    {
        $plan = Dataset::plan('metadata-test');
        $song = $plan['files'][0];
        // The adapter escapes the hyphen of the run name, and the transport reads the escape back.
        self::assertStringContainsString('-', $song['album']);
        $music = $this->musicBrainz();

        $hits = $music->findReleases($song['album'], $song['artist']);

        self::assertIsArray($hits);
        self::assertCount(1, $hits);
        self::assertSame($song['album'], $hits[0]->title);
        self::assertSame(20, $hits[0]->trackCount);
        self::assertSame($plan['albums'][$song['album']], $hits[0]->releaseGroup?->id);
        self::assertNotSame($plan['albums'][$song['album']], $hits[0]->id);
        self::assertSame([$plan['artists'][$song['artist']]], array_column($hits[0]->artistCredit->artists, 'artistId'));
        self::assertEquals($hits, $music->findReleases($song['album'], null));
        self::assertSame(
            array_fill(0, 2, ['path' => '/ws/2/release', 'identity' => $plan['albums'][$song['album']], 'outcome' => 'matched']),
            $this->audited(),
        );
    }

    #[RequiresPhpExtension('curl')]
    public function testReleaseLookupAnswersTheTracksOfTheAlbum(): void
    {
        $plan = Dataset::plan('metadata-test');
        $songs = array_slice($plan['files'], 0, 20);
        $album = $songs[0]['album'];
        $music = $this->musicBrainz();
        $hits = $music->findReleases($album, $songs[0]['artist']);
        self::assertIsArray($hits);

        $release = $music->fetchRelease($hits[0]->id);

        self::assertInstanceOf(MusicBrainzRelease::class, $release);
        self::assertSame($hits[0]->id, $release->id);
        self::assertSame($album, $release->title);
        self::assertSame($plan['albums'][$album], $release->releaseGroup?->id);
        self::assertFalse($release->hasFrontCover);
        self::assertSame([$plan['artists'][$songs[0]['artist']]], array_column($release->artistCredit->artists, 'artistId'));
        self::assertCount(1, $release->media);
        $tracks = $release->tracks();
        self::assertSame(array_column(array_column($songs, 'metadata'), 'id'), array_column($tracks, 'recordingId'));
        self::assertSame(array_column($songs, 'title'), array_column($tracks, 'title'));
        self::assertSame(range(1, 20), array_column($tracks, 'position'));
        self::assertCount(20, array_unique(array_column($tracks, 'id')));
        foreach ($tracks as $index => $track) {
            self::assertLessThanOrEqual(3000, abs((int) $track->lengthMs - $songs[$index]['duration'] * 1000));
            self::assertSame([$plan['artists'][$songs[$index]['artist']]], array_column($track->artistCredit->artists, 'artistId'));
        }
        self::assertSame(
            ['path' => '/ws/2/release/' . $release->id, 'identity' => $plan['albums'][$album], 'outcome' => 'matched'],
            $this->audited()[1],
        );
    }

    #[RequiresPhpExtension('curl')]
    public function testReleaseSearchForATitleOutsideTheDatasetFindsNoRelease(): void
    {
        $artist = Dataset::plan('metadata-test')['files'][0]['artist'];

        self::assertSame([], $this->musicBrainz()->findReleases('Qualification metadata-test Album 101', $artist));
        self::assertSame([['path' => '/ws/2/release', 'identity' => null, 'outcome' => 'matched']], $this->audited());
    }

    public function testReleaseSearchOfAnotherShapeStaysUnmatched(): void
    {
        $album = Dataset::plan('metadata-test')['files'][0]['album'];
        $transport = MetadataTransport::create($this->directory . '/manifest.json', $this->directory . '/audit.jsonl');
        try {
            $transport->request('GET', 'https://musicbrainz.org/ws/2/release?' . http_build_query(['query' => $album, 'fmt' => 'json']))->getContent();
            self::fail('A release search by bare title succeeded.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('Unmatched qualification metadata request', $error->getMessage());
        }
        self::assertSame([['path' => '/ws/2/release', 'identity' => null, 'outcome' => 'unmatched']], $this->audited());
    }

    #[RequiresPhpExtension('curl')]
    public function testArtistLookupAnswersACreditedArtistByItsId(): void
    {
        $plan = Dataset::plan('metadata-test');
        $name = $plan['files'][1999]['artist'];
        $id = $plan['artists'][$name];

        $artist = $this->musicBrainz()->fetchArtist($id);

        self::assertInstanceOf(MusicBrainzArtist::class, $artist);
        self::assertSame($id, $artist->id);
        self::assertSame($name, $artist->name);
        self::assertSame($name, $artist->sortName);
        self::assertSame('Person', $artist->type);
        self::assertSame([['path' => '/ws/2/artist/' . $id, 'identity' => $id, 'outcome' => 'matched']], $this->audited());
    }

    #[RequiresPhpExtension('curl')]
    public function testArtistLookupOfAnIdOutsideTheDatasetAnswersNotFound(): void
    {
        $id = 'a2f6b7c1-0d4e-4f3a-9b8c-5e6d7f8a9b0c';
        self::assertNotContains($id, Dataset::plan('metadata-test')['artists']);

        self::assertNull($this->musicBrainz()->fetchArtist($id));
        self::assertSame([['path' => '/ws/2/artist/' . $id, 'identity' => null, 'outcome' => 'matched']], $this->audited());
    }

    public function testAuditStopsAtItsBoundInsteadOfDroppingEvidence(): void
    {
        $path = $this->directory . '/audit.jsonl';
        $handle = fopen($path, 'wb');
        ftruncate($handle, 8 * 1024 * 1024);
        fclose($handle);
        $transport = MetadataTransport::create($this->directory . '/manifest.json', $path);
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Qualification metadata audit limit reached.');
        $transport->request('GET', 'https://musicbrainz.org/ws/2/recording?query=unknown&fmt=json')->getContent();
    }

    public function testMovieSearchAndLookupAgreeOnUniqueIdentifiers(): void
    {
        $plan = Dataset::plan('metadata-test');
        $movie = $plan['files'][2000];
        $transport = MetadataTransport::create($this->directory . '/manifest.json', $this->directory . '/audit.jsonl');
        $search = $transport->request('GET', 'https://api.themoviedb.org/3/search/movie?' . http_build_query(['query' => $movie['title'], 'page' => 1]))->toArray();
        $detail = $transport->request('GET', 'https://api.themoviedb.org/3/movie/' . $movie['metadata']['tmdbId'])->toArray();
        self::assertSame($search['results'][0]['id'], $detail['id']);
        self::assertSame($movie['title'], $detail['title']);
    }

    /** The application's MusicBrainz adapter over the recorded transport, with no limit on its pace. */
    private function musicBrainz(): MusicBrainzAdapter
    {
        $client = new PublicHttpClient(MetadataTransport::create($this->directory . '/manifest.json', $this->directory . '/audit.jsonl'), new PublicDestinationPolicy(MetadataTransport::dnsResolver()));
        $logger = new NullLogger();
        $encoder = new JsonEncoder();

        return new MusicBrainzAdapter(
            $logger,
            $encoder,
            $client,
            new RateLimiterFactory(['id' => 'musicbrainz', 'policy' => 'no_limit'], new InMemoryStorage()),
            new CoverArtArchiveAdapter($logger, $encoder, $client),
        );
    }

    /** @return list<array{path: string, identity: string|int|null, outcome: string}> the audited requests, oldest first */
    private function audited(): array
    {
        return array_map(
            static function (string $line): array {
                $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                return ['path' => $entry['path'], 'identity' => $entry['identity'], 'outcome' => $entry['outcome']];
            },
            file($this->directory . '/audit.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        );
    }
}
