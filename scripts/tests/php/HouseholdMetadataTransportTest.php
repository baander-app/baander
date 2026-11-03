<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use App\Shared\Infrastructure\Http\PublicDestinationPolicy;
use App\Shared\Infrastructure\Http\PublicHttpClient;
use Baander\Tooling\Fixtures\Household\Dataset;
use Baander\Tooling\Fixtures\Household\MetadataTransport;
use PHPUnit\Framework\TestCase;

final class HouseholdMetadataTransportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/household-metadata-' . bin2hex(random_bytes(8));
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
        $this->expectException(\App\Shared\Infrastructure\Http\UnsafeDestinationException::class);
        $client->request('GET', 'https://127.0.0.1/ws/2/recording', ['musicbrainz.org']);
    }

    public function testUnknownQueryIsRejectedAndAuditedWithoutLeakingHeaders(): void
    {
        $transport = MetadataTransport::create($this->directory . '/manifest.json', $this->directory . '/audit.jsonl');
        try {
            $transport->request('GET', 'https://musicbrainz.org/ws/2/recording?query=unknown&fmt=json', ['headers' => ['Authorization' => 'secret-canary']])->getContent();
            self::fail('Unknown fixture query succeeded.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('Unmatched household metadata request', $error->getMessage());
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
        $this->expectExceptionMessage('Unmatched household metadata request.');
        $transport->request('GET', $url)->getContent();
    }

    public function testRealAdaptersAcceptTheRecordedSearchAndLookupContracts(): void
    {
        $plan = Dataset::plan('metadata-test');
        $client = new PublicHttpClient(MetadataTransport::create($this->directory . '/manifest.json', $this->directory . '/audit.jsonl'), new PublicDestinationPolicy(MetadataTransport::dnsResolver()));
        $logger = new \Psr\Log\NullLogger();
        $encoder = new \Symfony\Component\Serializer\Encoder\JsonEncoder();
        $music = new \App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter($logger, $encoder, $client);
        $song = $plan['files'][0];
        self::assertSame($song['metadata']['id'], $music->searchRecording($song['title'], limit: 5)->recordings[0]->id);
        self::assertSame($plan['albums'][$song['album']], $music->searchReleaseGroup($song['album'], limit: 5)->releaseGroups[0]->id);
        self::assertSame($plan['artists'][$song['artist']], $music->searchArtist($song['artist'], limit: 5)->artists[0]->id);
        $tmdb = new \App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter('fixture-token', $logger, $encoder, $client);
        $movie = $plan['files'][2000];
        self::assertSame($movie['metadata']['tmdbId'], $tmdb->searchMovie($movie['title'], limit: 1)->first()->id);
        self::assertSame($movie['title'], $tmdb->lookupMovie($movie['metadata']['tmdbId'])->title);
    }

    public function testAuditStopsAtItsBoundInsteadOfDroppingEvidence(): void
    {
        $path = $this->directory . '/audit.jsonl';
        $handle = fopen($path, 'wb');
        ftruncate($handle, 8 * 1024 * 1024);
        fclose($handle);
        $transport = MetadataTransport::create($this->directory . '/manifest.json', $path);
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Household metadata audit limit reached.');
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
}
