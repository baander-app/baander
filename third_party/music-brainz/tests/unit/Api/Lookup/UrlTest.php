<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\Target\RelationList\RelationToAreaList;
use MusicBrainz\Relation\Target\RelationList\RelationToArtistList;
use MusicBrainz\Relation\Target\RelationList\RelationToEventList;
use MusicBrainz\Relation\Target\RelationList\RelationToInstrumentList;
use MusicBrainz\Relation\Target\RelationList\RelationToLabelList;
use MusicBrainz\Relation\Target\RelationList\RelationToPlaceList;
use MusicBrainz\Relation\Target\RelationList\RelationToRecordingList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseGroupList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseList;
use MusicBrainz\Relation\Target\RelationList\RelationToSeriesList;
use MusicBrainz\Relation\Target\RelationList\RelationToWorkList;
use MusicBrainz\Supplement\Lookup\UrlFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\URL;

/**
 * Unit tests for the lookup API.
 */
class UrlTest extends ApiTestCase
{
    /**
     * Test instance of the artist
     *
     * @var URL
     */
    private static URL $url;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$url)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'url/40c1c047-fa88-4f4a-bcc6-4e29a5c42679',
            [
                'fmt' => 'json',
                'inc' => 'area-rels+artist-rels+event-rels+instrument-rels+label-rels+place-rels+recording-rels+release-rels+release-group-rels+series-rels+work-rels',
            ],
            'Lookup/Url.json'
        );

        /**
         * Performing the test
         */
        $fields = (new UrlFields())
            // Relations
            ->includeAreaRelations()
            ->includeArtistRelations()
            ->includeEventRelations()
            ->includeInstrumentRelations()
            ->includeLabelRelations()
            ->includePlaceRelations()
            ->includeReleaseGroupRelations()
            ->includeReleaseRelations()
            ->includeRecordingRelations()
            ->includeSeriesRelations()
            ->includeWorkRelations();

        self::$url = $this->musicBrainz->api()->lookup()->url(
            new MBID('40c1c047-fa88-4f4a-bcc6-4e29a5c42679'),
            $fields
        );
    }

    /**
     * Checks the URL.
     *
     * @return void
     */
    public function testUrl(): void
    {
        $url = self::$url;

        $this->assertInstanceOf(Url::class, $url);
    }

    public function testRelations(): void
    {
        $relationList = self::$url->getRelations();

        $this->assertInstanceOf(\MusicBrainz\Relation\RelationList\UrlRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToAreaList::class, $relationList->getAreaRelations());
        $this->assertInstanceOf(RelationToArtistList::class, $relationList->getArtistRelations());
        $this->assertInstanceOf(RelationToEventList::class, $relationList->getEventRelations());
        $this->assertInstanceOf(RelationToInstrumentList::class, $relationList->getInstrumentRelations());
        $this->assertInstanceOf(RelationToLabelList::class, $relationList->getLabelRelations());
        $this->assertInstanceOf(RelationToPlaceList::class, $relationList->getPlaceRelations());
        $this->assertInstanceOf(RelationToRecordingList::class, $relationList->getRecordingRelations());
        $this->assertInstanceOf(RelationToReleaseGroupList::class, $relationList->getReleaseGroupRelations());
        $this->assertInstanceOf(RelationToReleaseList::class, $relationList->getReleaseRelations());
        $this->assertInstanceOf(RelationToSeriesList::class, $relationList->getSeriesRelations());
        $this->assertInstanceOf(RelationToWorkList::class, $relationList->getWorkRelations());
    }
}
