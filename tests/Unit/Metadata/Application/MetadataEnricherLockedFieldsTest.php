<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\Song;
use App\Metadata\Application\AlbumMetadataEnricher;
use App\Metadata\Application\ArtistMetadataEnricher;
use App\Metadata\Application\EnrichmentResult;
use App\Metadata\Application\SongMetadataEnricher;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A forced enrichment skips the fields an operator locked and still applies the others, and it
 * decides against the stored state re-read after the provider lookups, not the state it started
 * from, so an edit made during the lookups survives.
 *
 * applyData() is called directly: the provider adapters make their own HTTP requests, and
 * enrich() reports every exception as a failed enrichment, which would hide a rejected edit.
 */
final class MetadataEnricherLockedFieldsTest extends TestCase
{
    private const ALBUM_DATA = [
        'quality' => 1.0,
        'mbid' => null,
        'title' => 'Abbey Road [Parlophone,PCS 7088,GB]',
        'year' => '1969-09-26',
        'country' => null,
        'tags' => [],
        'genres' => [],
    ];

    public function testAForcedAlbumEnrichmentLeavesALockedLabelAndAppliesTheYear(): void
    {
        $album = Album::create(new Uuid(), 'Abbey Road', 'album', label: 'Apple');
        $album->lockField('label');
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects(self::once())->method('findFreshByUuid')->with($album->getId())->willReturn($album);
        $albums->expects(self::once())->method('save')->with($album);

        $result = $this->applyData($this->albumEnricher($albums), $album, self::ALBUM_DATA, 'musicbrainz');

        self::assertSame('Apple', $album->getLabel());
        self::assertSame(1969, $album->getYear());
        self::assertSame('PCS 7088', $album->getCatalogNumber());
        self::assertSame('GB', $album->getCountry());
        self::assertNotContains('label', $result->getUpdatedFields());
        self::assertContains('year', $result->getUpdatedFields());
    }

    public function testAnAlbumEnrichmentKeepsAYearLockAndCoverSetDuringTheLookups(): void
    {
        $loaded = Album::create(new Uuid(), 'Abbey Road', 'album');
        $stored = Album::reconstitute(clone $loaded->getState());
        $stored->updateMetadata(year: 2019);
        $stored->lockField('year');
        $cover = new Uuid();
        $stored->setCoverImage($cover);
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects(self::once())->method('findFreshByUuid')->with($loaded->getId())->willReturn($stored);
        $albums->expects(self::once())->method('save')->with(self::identicalTo($stored));

        $result = $this->applyData($this->albumEnricher($albums), $loaded, self::ALBUM_DATA, 'musicbrainz');

        self::assertSame(2019, $stored->getYear());
        self::assertTrue($stored->isFieldLocked('year'));
        self::assertSame($cover, $stored->getCoverImageId());
        self::assertSame('Parlophone', $stored->getLabel());
        self::assertSame('PCS 7088', $stored->getCatalogNumber());
        self::assertNotContains('year', $result->getUpdatedFields());
    }

    public function testAnAlbumDeletedDuringTheLookupsIsNotWritten(): void
    {
        $loaded = Album::create(new Uuid(), 'Abbey Road', 'album');
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects(self::once())->method('findFreshByUuid')->willReturn(null);
        $albums->expects(self::never())->method('save');

        $result = (new \ReflectionMethod(AlbumMetadataEnricher::class, 'applyData'))
            ->invoke($this->albumEnricher($albums), $loaded, self::ALBUM_DATA, 'musicbrainz', true);

        self::assertInstanceOf(EnrichmentResult::class, $result);
        self::assertFalse($result->isSuccess());
    }

    public function testAForcedArtistEnrichmentLeavesLockedFieldsAndAppliesTheRest(): void
    {
        $artist = Artist::create('The Beatles', country: 'GB');
        $artist->lockField('name');
        $artist->lockField('country');
        $artists = $this->createMock(ArtistPortInterface::class);
        $artists->expects(self::once())->method('findFreshByUuid')->with($artist->getId())->willReturn($artist);
        $artists->expects(self::once())->method('save')->with($artist);

        $result = $this->applyData($this->artistEnricher($artists), $artist, $this->artistData(), 'musicbrainz');

        self::assertSame('The Beatles', $artist->getName());
        self::assertSame('GB', $artist->getCountry());
        self::assertSame('Group', $artist->getType());
        self::assertSame('Beatles, The', $artist->getSortName());
        self::assertSame('1960-01-01', $artist->getLifeSpanBegin()?->format('Y-m-d'));
        self::assertSame(['type', 'sortName', 'lifeSpanBegin'], $result->getUpdatedFields());
    }

    public function testAnArtistEnrichmentKeepsACountryLockedDuringTheLookups(): void
    {
        $loaded = Artist::create('The Beatles');
        $stored = Artist::reconstitute(clone $loaded->getState());
        $stored->updateMetadata(country: 'GB');
        $stored->lockField('country');
        $artists = $this->createMock(ArtistPortInterface::class);
        $artists->expects(self::once())->method('findFreshByUuid')->with($loaded->getId())->willReturn($stored);
        $artists->expects(self::once())->method('save')->with(self::identicalTo($stored));

        $result = $this->applyData($this->artistEnricher($artists), $loaded, $this->artistData(), 'musicbrainz');

        self::assertSame('GB', $stored->getCountry());
        self::assertTrue($stored->isFieldLocked('country'));
        self::assertSame('Group', $stored->getType());
        self::assertNotContains('country', $result->getUpdatedFields());
    }

    public function testASongEnrichmentKeepsATitleEditedDuringTheLookups(): void
    {
        $loaded = Song::create(new Uuid(), 'Come Together', '/music/come-together.flac', 1024, 'audio/flac');
        $stored = Song::reconstitute(clone $loaded->getState());
        $stored->updateMetadata(title: 'Come Together (2019 Mix)');
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects(self::once())->method('findFreshByUuid')->with($loaded->getId())->willReturn($stored);
        $songs->expects(self::once())->method('save')->with(self::identicalTo($stored));
        $enricher = new SongMetadataEnricher(
            $this->adapter(MusicBrainzAdapter::class),
            $songs,
            $this->createStub(GenrePortInterface::class),
            new NullLogger(),
        );
        $mbid = '0b4f1a46-4a2e-4f3a-9d6c-6a3e2b7f1c11';

        $result = (new \ReflectionMethod($enricher, 'applyData'))->invoke($enricher, $loaded, [
            'source' => 'musicbrainz',
            'quality' => 1.0,
            'mbid' => $mbid,
            'title' => 'Come Together',
            'tags' => [],
        ], false);

        self::assertInstanceOf(EnrichmentResult::class, $result);
        self::assertTrue($result->isSuccess());
        self::assertSame('Come Together (2019 Mix)', $stored->getTitle());
        self::assertSame($mbid, $stored->getMbid());
    }

    private function albumEnricher(AlbumPortInterface $albums): AlbumMetadataEnricher
    {
        return new AlbumMetadataEnricher(
            $this->adapter(MusicBrainzAdapter::class),
            $this->adapter(DiscogsAdapter::class),
            $albums,
            $this->createStub(GenrePortInterface::class),
            new NullLogger(),
        );
    }

    private function artistEnricher(ArtistPortInterface $artists): ArtistMetadataEnricher
    {
        return new ArtistMetadataEnricher(
            $this->adapter(MusicBrainzAdapter::class),
            $this->adapter(DiscogsAdapter::class),
            $artists,
            new NullLogger(),
        );
    }

    /** @return array<string, mixed> */
    private function artistData(): array
    {
        return [
            'quality' => 1.0,
            'mbid' => null,
            'name' => 'Beatles',
            'sortName' => 'Beatles, The',
            'type' => 'Group',
            'country' => 'US',
            'disambiguation' => null,
            'lifeSpanBegin' => '1960',
            'lifeSpanEnd' => null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function applyData(object $enricher, object $target, array $data, string $source): EnrichmentResult
    {
        $result = (new \ReflectionMethod($enricher, 'applyData'))->invoke($enricher, $target, $data, $source, true);
        self::assertInstanceOf(EnrichmentResult::class, $result);
        self::assertTrue($result->isSuccess());

        return $result;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function adapter(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
