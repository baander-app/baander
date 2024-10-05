<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\RelationList\LabelRelationList;
use MusicBrainz\Relation\Target\RelationList\RelationToLabelList;
use MusicBrainz\Relation\Target\RelationList\RelationToRecordingList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseGroupList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseList;
use MusicBrainz\Relation\Target\RelationList\RelationToSeriesList;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Relation\Target\RelationList\RelationToWorkList;
use MusicBrainz\Supplement\Lookup\LabelFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Label;
use MusicBrainz\Value\MBID;

/**
 * Unit tests for the lookup API.
 */
class LabelTest extends ApiTestCase
{
    /**
     * Test instance of the event
     *
     * @var Label
     */
    private static Label $label;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$label)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'label/19d052fa-570a-4b17-9a3d-8f2f029b7b57',
            [
                'fmt' => 'json',
                'inc' => 'releases+discids+media+aliases+tags+user-tags+ratings+user-ratings+artist-rels+label-rels+recording-rels+release-rels+release-group-rels+url-rels+work-rels+annotation',
            ],
            'Lookup/Event.json'
        );

        /**
         * Performing the test
         */
        $fields = (new LabelFields())
            ->includeAnnotation()
            ->includeDiscIds()
            ->includeMedia()
            ->includeUserTags()
            ->includeUserRatings()
            ->includeTags()
            ->includeReleases()
            ->includeRatings()
            ->includeAliases()
            // Relations
            ->includeLabelRelations()
            ->includeArtistRelations()
            ->includeReleaseRelations()
            ->includeReleaseGroupRelations()
            ->includeRecordingRelations()
            ->includeUrlRelations()
            ->includeWorkRelations();

        self::$label = $this->musicBrainz->api()->lookup()->label(
            new MBID('19d052fa-570a-4b17-9a3d-8f2f029b7b57'),
            $fields
        );
    }

    /**
     * Checks the label.
     *
     * @return void
     */
    public function testLabel(): void
    {
        $event = self::$label;

        $this->assertInstanceOf(Label::class, $event);

        $this->assertInstanceOf(MBID::class, $event->getMBID());
        $this->assertEquals('9e94c410-54e6-4f32-981d-ae49642a3721', $event->getMBID());
    }

    public function testRelations(): void
    {
        $relationList = self::$label->getRelations();

        $this->assertInstanceOf(LabelRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToLabelList::class, $relationList->getLabelRelations());
        $this->assertInstanceOf(RelationToReleaseGroupList::class, $relationList->getReleaseGroupRelations());
        $this->assertInstanceOf(RelationToReleaseList::class, $relationList->getReleaseRelations());
        $this->assertInstanceOf(RelationToRecordingList::class, $relationList->getRecordingRelations());
        $this->assertInstanceOf(RelationToSeriesList::class, $relationList->getSeriesRelations());
        $this->assertInstanceOf(RelationToUrlList::class, $relationList->getUrlRelations());
        $this->assertInstanceOf(RelationToWorkList::class, $relationList->getWorkRelations());
    }
}
