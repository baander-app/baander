<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application;

use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Metadata\Application\ArtistMetadataEnricher;
use App\Metadata\Application\EnrichmentResult;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A forced artist enrichment skips the fields an operator locked and still applies the others, and
 * it decides against the stored state re-read after the provider lookups, not the state it started
 * from, so an edit made during the lookups survives.
 *
 * applyData() is called directly: the provider adapters make their own HTTP requests, and
 * enrich() reports every exception as a failed enrichment, which would hide a rejected edit.
 */
final class MetadataEnricherLockedFieldsTest extends TestCase
{
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

    public function testAnArtistDeletedDuringTheLookupsIsNotWritten(): void
    {
        $loaded = Artist::create('The Beatles');
        $artists = $this->createMock(ArtistPortInterface::class);
        $artists->expects(self::once())->method('findFreshByUuid')->with($loaded->getId())->willReturn(null);
        $artists->expects(self::never())->method('save');

        $result = (new \ReflectionMethod(ArtistMetadataEnricher::class, 'applyData'))
            ->invoke($this->artistEnricher($artists), $loaded, $this->artistData(), 'musicbrainz', true);

        self::assertInstanceOf(EnrichmentResult::class, $result);
        self::assertFalse($result->isSuccess());
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
