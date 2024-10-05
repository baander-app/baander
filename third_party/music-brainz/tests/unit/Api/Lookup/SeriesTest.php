<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\Target\RelationList\RelationToArtistList;
use MusicBrainz\Relation\Target\RelationList\RelationToEventList;
use MusicBrainz\Relation\Target\RelationList\RelationToLabelList;
use MusicBrainz\Relation\Target\RelationList\RelationToRecordingList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseGroupList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseList;
use MusicBrainz\Relation\Target\RelationList\RelationToSeriesList;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Relation\Target\RelationList\RelationToWorkList;
use MusicBrainz\Supplement\Lookup\SeriesFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Series;

/**
 * Unit tests for the lookup API.
 */
class SeriesTest extends ApiTestCase
{
    /**
     * Test instance of the series
     *
     * @var Series
     */
    private static Series $series;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$series)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'series/1df4b695-967a-4294-8bb2-856a22f0c726',
            [
                'fmt' => 'json',
                'inc' => 'artist-rels+event-rels+label-rels+recording-rels+release-rels+release-group-rels+series-rels+url-rels+work-rels',
            ],
            'Lookup/Series.json'
        );
        $fields = (new SeriesFields())
            // Relations
            ->includeArtistRelations()
            ->includeEventRelations()
            ->includeLabelRelations()
            ->includeRecordingRelations()
            ->includeReleaseRelations()
            ->includeReleaseGroupRelations()
            ->includeSeriesRelations()
            ->includeUrlRelations()
            ->includeWorkRelations();

        /**
         * Performing the test
         */
        self::$series = $this->musicBrainz->api()->lookup()->series(
            new MBID('1df4b695-967a-4294-8bb2-856a22f0c726'),
            $fields
        );
    }

    /**
     * Checks the release.
     *
     * @return void
     */
    public function testRelease(): void
    {
        $artist = self::$series;

        $this->assertInstanceOf(Series::class, $artist);
    }

    public function testRelations(): void
    {
        $relationList = self::$series->getRelations();

        $this->assertInstanceOf(\MusicBrainz\Relation\RelationList\SeriesRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToArtistList::class, $relationList->getArtistRelations());
        $this->assertInstanceOf(RelationToEventList::class, $relationList->getEventRelations());
        $this->assertInstanceOf(RelationToLabelList::class, $relationList->getLabelRelations());
        $this->assertInstanceOf(RelationToRecordingList::class, $relationList->getRecordingRelations());
        $this->assertInstanceOf(RelationToReleaseList::class, $relationList->getReleaseRelations());
        $this->assertInstanceOf(RelationToReleaseGroupList::class, $relationList->getReleaseGroupRelations());
        $this->assertInstanceOf(RelationToSeriesList::class, $relationList->getSeriesRelations());
        $this->assertInstanceOf(RelationToUrlList::class, $relationList->getUrlRelations());
        $this->assertInstanceOf(RelationToWorkList::class, $relationList->getWorkRelations());
    }
}
