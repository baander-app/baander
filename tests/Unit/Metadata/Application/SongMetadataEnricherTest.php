<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application;

use App\Tests\Fixtures\Metadata\ProviderSwitches;
use App\Metadata\Application\Settings\MetadataProvider;
use App\Metadata\Application\Settings\MetadataProviderSwitches;

use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Genre;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\ValueObject\MatchedSongField;
use App\Metadata\Application\SongMetadataEnricher;
use App\Metadata\Infrastructure\Api\CoverArtArchive\CoverArtArchiveAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Shared\Domain\Model\Uuid;
use Baander\HttpClient\PublicDestinationPolicy;
use Baander\HttpClient\PublicHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * A song's genre sync fills genres from the best recording a title search finds and writes nothing
 * else: no recording ID, which only a MusicBrainz match gives, and no field a match set.
 */
final class SongMetadataEnricherTest extends TestCase
{
    private const string SEARCH_HIT = '{"count":1,"recordings":[{"id":"0b4f1a46-4a2e-4f3a-9d6c-6a3e2b7f1c11","score":100,"title":"Pour Me a Drink","tags":[{"name":"country","count":3}]}]}';

    public function testAForcedGenreSyncAddsGenresAndWritesNoRecordingIdOrMatchedField(): void
    {
        $song = Song::create(new Uuid(), 'Pour Me A Drink', '/music/F-1 Trillion/01 Pour Me A Drink.flac', 1024, 'audio/flac');
        $song->recordMatchedFields(MatchedSongField::Title, MatchedSongField::Credits);
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects(self::once())->method('findFreshByUuid')->with($song->getId())->willReturn($song);
        $songs->expects(self::never())->method('save');
        $country = Genre::create('country', 'country');
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->expects(self::once())->method('findOrCreateByName')->with('country')->willReturn($country);
        $genres->expects(self::once())->method('addSongToGenre')->with($country->getId(), $song->getId())->willReturn(true);

        $result = $this->enricher($songs, $genres)->enrich($song, forceUpdate: true);

        self::assertTrue($result->isSuccess());
        self::assertSame(['genres'], $result->getUpdatedFields());
        self::assertFalse($result->hasIdentifiersUpdated());
        self::assertNull($song->getMbid());
        self::assertSame('Pour Me A Drink', $song->getTitle());
        self::assertSame([MatchedSongField::Title, MatchedSongField::Credits], $song->getMatchedFields());
    }

    public function testASongDeletedDuringTheLookupIsNotWritten(): void
    {
        $song = Song::create(new Uuid(), 'Pour Me A Drink', '/music/F-1 Trillion/01 Pour Me A Drink.flac', 1024, 'audio/flac');
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects(self::once())->method('findFreshByUuid')->with($song->getId())->willReturn(null);
        $songs->expects(self::never())->method('save');
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->expects(self::never())->method('addSongToGenre');

        $result = $this->enricher($songs, $genres)->enrich($song, forceUpdate: true);

        self::assertFalse($result->isSuccess());
    }

    public function testDisabledMusicBrainzPreservesTheSongAndReportsDisabledWithoutRequestsOrWrites(): void
    {
        $song = Song::create(new Uuid(), 'Kept title', '/music/album/track.flac', 1024, 'audio/flac');
        $song->recordMatchedFields(MatchedSongField::Title);
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects(self::never())->method('findFreshByUuid');
        $songs->expects(self::never())->method('save');
        $genres = $this->createMock(GenrePortInterface::class);
        $genres->expects(self::never())->method('findOrCreateByName');
        $genres->expects(self::never())->method('addSongToGenre');
        $http = new MockHttpClient(static fn () => throw new \LogicException('No provider request is expected.'));

        $result = $this->enricher($songs, $genres, ProviderSwitches::withDisabled(MetadataProvider::MusicBrainz), $http)->enrich($song, true);

        self::assertTrue($result->isDisabled());
        self::assertSame('Kept title', $song->getTitle());
        self::assertSame([MatchedSongField::Title], $song->getMatchedFields());
        self::assertSame(0, $http->getRequestsCount());
    }

    private function enricher(SongPortInterface $songs, GenrePortInterface $genres, ?MetadataProviderSwitches $switches = null, ?MockHttpClient $raw = null): SongMetadataEnricher
    {
        $logger = new NullLogger();
        $encoder = new JsonEncoder();
        $http = new PublicHttpClient(
            $raw ?? new MockHttpClient(static fn (): MockResponse => new MockResponse(self::SEARCH_HIT)),
            new PublicDestinationPolicy(static fn (string $host): array => ['1.1.1.1']),
        );
        $limiter = new RateLimiterFactory(['id' => 'musicbrainz', 'policy' => 'no_limit'], new InMemoryStorage());

        return new SongMetadataEnricher(
            new MusicBrainzAdapter($logger, $encoder, $http, $limiter, new CoverArtArchiveAdapter($logger, $encoder, $http, $switches ?? ProviderSwitches::allEnabled()), $switches ?? ProviderSwitches::allEnabled()),
            $songs,
            $genres,
            $logger,
        );
    }
}
