<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Artist;
use App\Metadata\Application\AlbumMetadataEnricher;
use App\Metadata\Application\ArtistMetadataEnricher;
use App\Metadata\Application\EnrichmentResult;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A forced enrichment skips the fields an operator locked and still applies the others.
 *
 * applyData() is called directly: the provider adapters make their own HTTP requests, and
 * enrich() reports every exception as a failed enrichment, which would hide a rejected edit.
 */
final class MetadataEnricherLockedFieldsTest extends TestCase
{
    public function testAForcedAlbumEnrichmentLeavesALockedLabelAndAppliesTheYear(): void
    {
        $album = Album::create(new Uuid(), 'Abbey Road', 'album', label: 'Apple');
        $album->lockField('label');
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects(self::once())->method('save')->with($album);
        $enricher = new AlbumMetadataEnricher(
            $this->adapter(MusicBrainzAdapter::class),
            $this->adapter(DiscogsAdapter::class),
            $albums,
            $this->createStub(GenrePortInterface::class),
            new NullLogger(),
        );

        $result = $this->applyData($enricher, $album, [
            'quality' => 1.0,
            'mbid' => null,
            'title' => 'Abbey Road [Parlophone,PCS 7088,GB]',
            'year' => '1969-09-26',
            'country' => null,
            'tags' => [],
            'genres' => [],
        ], 'musicbrainz');

        self::assertSame('Apple', $album->getLabel());
        self::assertSame(1969, $album->getYear());
        self::assertSame('PCS 7088', $album->getCatalogNumber());
        self::assertSame('GB', $album->getCountry());
        self::assertNotContains('label', $result->getUpdatedFields());
        self::assertContains('year', $result->getUpdatedFields());
    }

    public function testAForcedArtistEnrichmentLeavesLockedFieldsAndAppliesTheRest(): void
    {
        $artist = Artist::create('The Beatles', country: 'GB');
        $artist->lockField('name');
        $artist->lockField('country');
        $artists = $this->createMock(ArtistPortInterface::class);
        $artists->expects(self::once())->method('save')->with($artist);
        $enricher = new ArtistMetadataEnricher(
            $this->adapter(MusicBrainzAdapter::class),
            $this->adapter(DiscogsAdapter::class),
            $artists,
            new NullLogger(),
        );

        $result = $this->applyData($enricher, $artist, [
            'quality' => 1.0,
            'mbid' => null,
            'name' => 'Beatles',
            'sortName' => 'Beatles, The',
            'type' => 'Group',
            'country' => 'US',
            'disambiguation' => null,
            'lifeSpanBegin' => '1960',
            'lifeSpanEnd' => null,
        ], 'musicbrainz');

        self::assertSame('The Beatles', $artist->getName());
        self::assertSame('GB', $artist->getCountry());
        self::assertSame('Group', $artist->getType());
        self::assertSame('Beatles, The', $artist->getSortName());
        self::assertSame('1960-01-01', $artist->getLifeSpanBegin()?->format('Y-m-d'));
        self::assertSame(['type', 'sortName', 'lifeSpanBegin'], $result->getUpdatedFields());
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
