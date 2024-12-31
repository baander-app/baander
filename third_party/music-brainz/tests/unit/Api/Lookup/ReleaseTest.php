<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\Target\RelationList\RelationToAreaList;
use MusicBrainz\Relation\Target\RelationList\RelationToArtistList;
use MusicBrainz\Relation\Target\RelationList\RelationToEventList;
use MusicBrainz\Relation\Target\RelationList\RelationToLabelList;
use MusicBrainz\Relation\Target\RelationList\RelationToRecordingList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseList;
use MusicBrainz\Relation\Target\RelationList\RelationToSeriesList;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Supplement\Lookup\ReleaseFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Release;

/**
 * Unit tests for the lookup API.
 */
class ReleaseTest extends ApiTestCase
{
    /**
     * Test instance of the artist
     *
     * @var Release
     */
    private static Release $release;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$release)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'release/a146429a-cedc-3ab0-9e41-1aaf5f6cdc2d',
            [
                'fmt' => 'json',
                'inc' => 'collections+labels+recordings+releases+media+artist-credits+discids+annotation+aliases+area-rels+artist-rels+event-rels+label-rels+recording-rels+release-rels+series-rels+url-rels+recording-level-rels+work-level-rels',
            ],
            'Lookup/Release.json'
        );
        $fields = (new ReleaseFields())
            // Relations
            ->includeArtistRelations()
            ->includeCollections()
            ->includeLabels()
            ->includeRecordings()
            ->includeReleases()
            ->includeMedia()
            ->includeArtistCredits()
            ->includeDiscIds()
            ->includeAnnotation()
            ->includeAliases()
            // relations
            ->includeAreaRelations()
            ->includeArtistRelations()
            ->includeEventRelations()
            ->includeLabelRelations()
            ->includeRecordingRelations()
            ->includeReleaseRelations()
            ->includeSeriesRelations()
            ->includeUrlRelations()
            //
            ->includeRecordingLevelRelations()
            ->includeWorkLevelRelations();

        /**
         * Performing the test
         */
        self::$release = $this->musicBrainz->api()->lookup()->release(
            new MBID('a146429a-cedc-3ab0-9e41-1aaf5f6cdc2d'),
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
        $artist = self::$release;

        $this->assertInstanceOf(Release::class, $artist);
    }

    public function testRelations(): void
    {
        $relationList = self::$release->getRelations();

        $this->assertInstanceOf(\MusicBrainz\Relation\RelationList\ReleaseRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToAreaList::class, $relationList->getAreaRelations());
        $this->assertInstanceOf(RelationToArtistList::class, $relationList->getArtistRelations());
        $this->assertInstanceOf(RelationToEventList::class, $relationList->getEventRelations());
        $this->assertInstanceOf(RelationToLabelList::class, $relationList->getLabelRelations());
        $this->assertInstanceOf(RelationToRecordingList::class, $relationList->getRecordingRelations());
        $this->assertInstanceOf(RelationToReleaseList::class, $relationList->getReleaseRelations());
        $this->assertInstanceOf(RelationToSeriesList::class, $relationList->getSeriesRelations());
        $this->assertInstanceOf(RelationToUrlList::class, $relationList->getUrlRelations());
    }
}
