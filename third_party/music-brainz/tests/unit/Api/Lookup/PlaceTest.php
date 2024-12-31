<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\Target\RelationList\RelationToArtistList;
use MusicBrainz\Relation\Target\RelationList\RelationToPlaceList;
use MusicBrainz\Relation\Target\RelationList\RelationToRecordingList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseList;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Relation\Target\RelationList\RelationToWorkList;
use MusicBrainz\Supplement\Lookup\PlaceFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Place;

/**
 * Unit tests for the lookup API.
 */
class PlaceTest extends ApiTestCase
{
    /**
     * Test instance of the artist
     *
     * @var Place
     */
    private static Place $place;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$place)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'place/4352063b-a833-421b-a420-e7fb295dece0',
            [
                'fmt' => 'json',
                'inc' => 'artist-rels+place-rels+recording-rels+release-rels+url-rels+work-rels',
            ],
            'Lookup/Place.json'
        );

        /**
         * Performing the test
         */
        $fields = (new PlaceFields())
            // Relations
            ->includeArtistRelations()
            ->includePlaceRelations()
            ->includeReleaseRelations()
            ->includeRecordingRelations()
            ->includeUrlRelations()
            ->includeWorkRelations();

        self::$place = $this->musicBrainz->api()->lookup()->place(
            new MBID('4352063b-a833-421b-a420-e7fb295dece0'),
            $fields
        );
    }

    /**
     * Checks the place.
     *
     * @return void
     */
    public function testPlace(): void
    {
        $place = self::$place;

        $this->assertInstanceOf(Place::class, $place);
    }

    public function testRelations(): void
    {
        $relationList = self::$place->getRelations();

        $this->assertInstanceOf(\MusicBrainz\Relation\RelationList\PlaceRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToArtistList::class, $relationList->getArtistRelations());
        $this->assertInstanceOf(RelationToPlaceList::class, $relationList->getPlaceRelations());
        $this->assertInstanceOf(RelationToRecordingList::class, $relationList->getRecordingRelations());
        $this->assertInstanceOf(RelationToReleaseList::class, $relationList->getReleaseRelations());
        $this->assertInstanceOf(RelationToUrlList::class, $relationList->getUrlRelations());
        $this->assertInstanceOf(RelationToWorkList::class, $relationList->getWorkRelations());
    }
}
