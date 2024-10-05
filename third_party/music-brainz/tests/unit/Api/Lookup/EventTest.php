<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\RelationList\EventRelationList;
use MusicBrainz\Relation\Target\RelationList\RelationToArtistList;
use MusicBrainz\Relation\Target\RelationList\RelationToEventList;
use MusicBrainz\Relation\Target\RelationList\RelationToPlaceList;
use MusicBrainz\Relation\Target\RelationList\RelationToRecordingList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseGroupList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseList;
use MusicBrainz\Relation\Target\RelationList\RelationToSeriesList;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Supplement\Lookup\EventFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Event;
use MusicBrainz\Value\MBID;

/**
 * Unit tests for the lookup API.
 */
class EventTest extends ApiTestCase
{
    /**
     * Test instance of the event
     *
     * @var Event
     */
    private static Event $event;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$event)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'event/9e94c410-54e6-4f32-981d-ae49642a3721',
            [
                'fmt' => 'json',
                'inc' => 'area-rels+artist-rels+event-rels+place-rels+recording-rels+release-group-rels+release-rels+series-rels+url-rels+work-rels',
            ],
            'Lookup/Event.json'
        );

        /**
         * Performing the test
         */
        $fields = (new EventFields())
            // Relations
            ->includeAreaRelations()
            ->includeArtistRelations()
            ->includeEventRelations()
            ->includePlaceRelations()
            ->includeReleaseRelations()
            ->includeReleaseGroupRelations()
            ->includeRecordingRelations()
            ->includeSeriesRelations()
            ->includeUrlRelations()
            ->includeWorkRelations();

        self::$event = $this->musicBrainz->api()->lookup()->event(
            new MBID('9e94c410-54e6-4f32-981d-ae49642a3721'),
            $fields
        );
    }

    /**
     * Checks the event.
     *
     * @return void
     */
    public function testEvent(): void
    {
        $event = self::$event;

        $this->assertInstanceOf(Event::class, $event);

        $this->assertInstanceOf(MBID::class, $event->getMBID());
        $this->assertEquals('9e94c410-54e6-4f32-981d-ae49642a3721', $event->getMBID());
    }

    public function testRelations(): void
    {
        $relationList = self::$event->getRelations();

        $this->assertInstanceOf(EventRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToArtistList::class, $relationList->getArtistRelations());
        $this->assertInstanceOf(RelationToArtistList::class, $relationList->getArtistRelations());
        $this->assertInstanceOf(RelationToEventList::class, $relationList->getEventRelations());
        $this->assertInstanceOf(RelationToPlaceList::class, $relationList->getPlaceRelations());
        $this->assertInstanceOf(RelationToReleaseGroupList::class, $relationList->getReleaseGroupRelations());
        $this->assertInstanceOf(RelationToReleaseList::class, $relationList->getReleaseRelations());
        $this->assertInstanceOf(RelationToRecordingList::class, $relationList->getRecordingRelations());
        $this->assertInstanceOf(RelationToSeriesList::class, $relationList->getSeriesRelations());
        $this->assertInstanceOf(RelationToUrlList::class, $relationList->getUrlRelations());
    }
}
