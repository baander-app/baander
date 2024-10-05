<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\Target\RelationList\RelationToAreaList;
use MusicBrainz\Relation\Target\RelationList\RelationToArtistList;
use MusicBrainz\Relation\Target\RelationList\RelationToEventList;
use MusicBrainz\Relation\Target\RelationList\RelationToLabelList;
use MusicBrainz\Relation\Target\RelationList\RelationToPlaceList;
use MusicBrainz\Relation\Target\RelationList\RelationToRecordingList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseList;
use MusicBrainz\Relation\Target\RelationList\RelationToSeriesList;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Relation\Target\RelationList\RelationToWorkList;
use MusicBrainz\Supplement\Lookup\RecordingFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Recording;

/**
 * Unit tests for the lookup API.
 */
class RecordingTest extends ApiTestCase
{
    /**
     * Test instance of the artist
     *
     * @var Recording
     */
    private static Recording $recording;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$recording)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'recording/61309059-1758-40d6-bf1b-52dedbd7b62b',
            [
                'fmt' => 'json',
                'inc' => 'area-rels+artist-rels+event-rels+label-rels+place-rels+recording-rels+release-rels+series-rels+url-rels+work-rels',
            ],
            'Lookup/Recording.json'
        );

        /**
         * Performing the test
         */
        $fields = (new RecordingFields())
            // Relations
            ->includeAreaRelations()
            ->includeArtistRelations()
            ->includeEventRelations()
            ->includeLabelRelations()
            ->includePlaceRelations()
            ->includeRecordingRelations()
            ->includeReleaseRelations()
            ->includeSeriesRelations()
            ->includeUrlRelations()
            ->includeWorkRelations();

        self::$recording = $this->musicBrainz->api()->lookup()->recording(
            new MBID('61309059-1758-40d6-bf1b-52dedbd7b62b'),
            $fields
        );
    }

    /**
     * Checks the artist.
     *
     * @return void
     */
    public function testRecording(): void
    {
        $artist = self::$recording;

        $this->assertInstanceOf(Recording::class, $artist);
    }

    public function testRelations(): void
    {
        $relationList = self::$recording->getRelations();

        $this->assertInstanceOf(\MusicBrainz\Relation\RelationList\RecordingRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToAreaList::class, $relationList->getAreaRelations());
        $this->assertInstanceOf(RelationToArtistList::class, $relationList->getArtistRelations());
        $this->assertInstanceOf(RelationToEventList::class, $relationList->getEventRelations());
        $this->assertInstanceOf(RelationToLabelList::class, $relationList->getLabelRelations());
        $this->assertInstanceOf(RelationToPlaceList::class, $relationList->getPlaceRelations());
        $this->assertInstanceOf(RelationToRecordingList::class, $relationList->getRecordingRelations());
        $this->assertInstanceOf(RelationToReleaseList::class, $relationList->getReleaseRelations());
        $this->assertInstanceOf(RelationToSeriesList::class, $relationList->getSeriesRelations());
        $this->assertInstanceOf(RelationToUrlList::class, $relationList->getUrlRelations());
        $this->assertInstanceOf(RelationToWorkList::class, $relationList->getWorkRelations());
    }
}
