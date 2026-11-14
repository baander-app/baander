<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application;

use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Metadata\Application\ArtistMetadataEnricher;
use App\Metadata\Application\EnrichmentResult;
use App\Metadata\Infrastructure\Api\Discogs\DiscogsAdapter;
use App\Metadata\Infrastructure\Api\MusicBrainz\MusicBrainzAdapter;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A MusicBrainz ID found by an artist search reaches the artist only through Catalog's guarded
 * assignment, which refuses an artist that already has an ID, an ID another artist holds and a
 * hit whose name differs from the artist's. The enricher's own save carries the ID the artist
 * already holds and never the searched one, and the other fields apply whatever Catalog decides.
 *
 * applyData() is called directly: the provider adapters make their own HTTP requests, and the
 * search is not what these tests are about.
 */
final class ArtistMetadataEnricherTest extends TestCase
{
    private const string TAGGED_ID = '6b4f1a46-4a2e-4f3a-9d6c-6a3e2b7f1c22';
    private const string SEARCHED_ID = '0b4f1a46-4a2e-4f3a-9d6c-6a3e2b7f1c11';

    private ?Artist $saved = null;

    public function testAForcedSyncKeepsTheIdTheArtistTookFromItsTags(): void
    {
        $artist = Artist::create('Headhunterz', mbid: self::TAGGED_ID);
        $artists = $this->createMock(ArtistPortInterface::class);
        $artists->method('findFreshByUuid')->willReturn($artist);
        $this->expectOneSave($artists);

        $result = $this->applyData($artists, $artist, $this->searchHit('Headhunterz'), forceUpdate: true);

        self::assertSame(self::TAGGED_ID, $this->saved()->getMbid());
        self::assertSame('Person', $this->saved()->getType());
        self::assertNotContains('mbid', $result->getUpdatedFields());
        self::assertFalse($result->hasIdentifiersUpdated());
    }

    public function testAnIdCatalogAssignsIsReportedAndTheSaveDoesNotCarryIt(): void
    {
        $artist = Artist::create('Post Malone');
        $artists = $this->createMock(ArtistPortInterface::class);
        $artists->method('findFreshByUuid')->willReturn($artist);
        $artists->expects(self::once())
            ->method('assignMusicbrainzId')
            ->with($artist->getId(), self::SEARCHED_ID, 'Post Malone')
            ->willReturn(true);
        $this->expectOneSave($artists);

        $result = $this->applyData($artists, $artist, $this->searchHit('Post Malone'), forceUpdate: false);

        self::assertNull($this->saved()->getMbid());
        self::assertSame('Person', $this->saved()->getType());
        self::assertSame(['country', 'type', 'sortName', 'mbid'], $result->getUpdatedFields());
        self::assertTrue($result->hasIdentifiersUpdated());
    }

    public function testTheOtherFieldsApplyWhenCatalogRefusesTheId(): void
    {
        $artist = Artist::create('Post Malone, Hank Williams Jr.');
        $artists = $this->createMock(ArtistPortInterface::class);
        $artists->method('findFreshByUuid')->willReturn($artist);
        $artists->expects(self::once())
            ->method('assignMusicbrainzId')
            ->with($artist->getId(), self::SEARCHED_ID, 'Hank Williams, Jr.')
            ->willReturn(false);
        $this->expectOneSave($artists);

        $result = $this->applyData($artists, $artist, $this->searchHit('Hank Williams, Jr.'), forceUpdate: false);

        self::assertNull($this->saved()->getMbid());
        self::assertSame('Person', $this->saved()->getType());
        self::assertSame('US', $this->saved()->getCountry());
        self::assertSame(['country', 'type', 'sortName'], $result->getUpdatedFields());
        self::assertFalse($result->hasIdentifiersUpdated());
    }

    private function expectOneSave(ArtistPortInterface&MockObject $artists): void
    {
        $artists->expects(self::once())->method('save')->willReturnCallback(function (Artist $artist): void {
            $this->saved = Artist::reconstitute(clone $artist->getState());
        });
    }

    /** The artist as the enricher saved it. */
    private function saved(): Artist
    {
        self::assertNotNull($this->saved, 'The enricher saved no artist.');

        return $this->saved;
    }

    /** @return array<string, mixed> */
    private function searchHit(string $name): array
    {
        return [
            'source' => 'musicbrainz',
            'quality' => 1.0,
            'mbid' => self::SEARCHED_ID,
            'name' => $name,
            'sortName' => $name,
            'type' => 'Person',
            'country' => 'US',
            'disambiguation' => null,
            'lifeSpanBegin' => null,
            'lifeSpanEnd' => null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function applyData(ArtistPortInterface $artists, Artist $artist, array $data, bool $forceUpdate): EnrichmentResult
    {
        $enricher = new ArtistMetadataEnricher(
            (new \ReflectionClass(MusicBrainzAdapter::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(DiscogsAdapter::class))->newInstanceWithoutConstructor(),
            $artists,
            new NullLogger(),
        );
        $result = (new \ReflectionMethod($enricher, 'applyData'))->invoke($enricher, $artist, $data, 'musicbrainz', $forceUpdate);
        self::assertInstanceOf(EnrichmentResult::class, $result);
        self::assertTrue($result->isSuccess());

        return $result;
    }
}
