<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\Target\RelationList\RelationToArtistList;
use MusicBrainz\Relation\Target\RelationList\RelationToEventList;
use MusicBrainz\Relation\Target\RelationList\RelationToLabelList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseGroupList;
use MusicBrainz\Relation\Target\RelationList\RelationToSeriesList;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Supplement\Lookup\ReleaseGroupFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\ReleaseGroup;

/**
 * Unit tests for the lookup API.
 */
class ReleaseGroupTest extends ApiTestCase
{
    /**
     * Test instance of the artist
     *
     * @var ReleaseGroup
     */
    private static ReleaseGroup $releaseGroup;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$releaseGroup)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'release-group/e8f70201-8899-3f0c-9e07-5d6495bc8046',
            [
                'fmt' => 'json',
                'inc' => 'aliases+artists+releases+discids+media+artist-credits+tags+user-tags+ratings+user-ratings+artist-rels+event-rels+label-rels+release-group-rels+series-rels+url-rels',
            ],
            'Lookup/ReleaseGroup.json'
        );
        $fields = (new ReleaseGroupFields())
            ->includeAliases()
            ->includeArtists()
            ->includeReleases()
            ->includeDiscIds()
            ->includeMedia()
            ->includeArtistCredits()
            ->includeTags()
            ->includeUserTags()
            ->includeRatings()
            ->includeUserRatings()
            // relations
            ->includeArtistRelations()
            ->includeEventRelations()
            ->includeLabelRelations()
            ->includeReleaseGroupRelations()
            ->includeSeriesRelations()
            ->includeUrlRelations();

        /**
         * Performing the test
         */
        self::$releaseGroup = $this->musicBrainz->api()->lookup()->releaseGroup(
            new MBID('e8f70201-8899-3f0c-9e07-5d6495bc8046'),
            $fields
        );
    }

    /**
     * Checks the release group.
     *
     * @return void
     */
    public function testReleaseGroup(): void
    {
        $artist = self::$releaseGroup;

        $this->assertInstanceOf(ReleaseGroup::class, $artist);
    }

    public function testRelations(): void
    {
        $relationList = self::$releaseGroup->getRelations();

        $this->assertInstanceOf(\MusicBrainz\Relation\RelationList\ReleaseGroupRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToArtistList::class, $relationList->getArtistRelations());
        $this->assertInstanceOf(RelationToEventList::class, $relationList->getEventRelations());
        $this->assertInstanceOf(RelationToLabelList::class, $relationList->getLabelRelations());
        $this->assertInstanceOf(RelationToReleaseGroupList::class, $relationList->getReleaseGroupRelations());
        $this->assertInstanceOf(RelationToSeriesList::class, $relationList->getSeriesRelations());
        $this->assertInstanceOf(RelationToUrlList::class, $relationList->getUrlRelations());
    }
}
