<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\Target\RelationList\RelationToAreaList;
use MusicBrainz\Relation\Target\RelationList\RelationToEventList;
use MusicBrainz\Relation\Target\RelationList\RelationToInstrumentList;
use MusicBrainz\Relation\Target\RelationList\RelationToRecordingList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseList;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Relation\Target\RelationList\RelationToWorkList;
use MusicBrainz\Relation\Target\RelationToWork;
use MusicBrainz\Relation\Type;
use MusicBrainz\Supplement\Lookup\AreaFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Area;
use MusicBrainz\Value\Language;
use MusicBrainz\Value\LanguageList;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Title;
use MusicBrainz\Value\Work;

/**
 * Unit tests for the lookup API.
 */
class AreaTest extends ApiTestCase
{
    /**
     * Test instance of the area
     *
     * @var Area
     */
    private static Area $area;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$area)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'area/20619e36-fca8-4499-bcc8-be01a3ea3e41',
            [
                'fmt' => 'json',
                'inc' => 'area-rels+event-rels+instrument-rels+recording-rels+release-rels+url-rels+work-rels',
            ],
            'Lookup/Area.json'
        );

        /**
         * Performing the test
         */
        $fields = (new AreaFields())
            ->includeAreaRelations()
            ->includeEventRelations()
            ->includeInstrumentRelations()
            ->includeReleaseRelations()
            ->includeRecordingRelations()
            ->includeUrlRelations()
            ->includeWorkRelations();

        self::$area = $this->musicBrainz->api()->lookup()->area(
            new MBID('20619e36-fca8-4499-bcc8-be01a3ea3e41'),
            $fields
        );
    }

    /**
     * Checks the area.
     *
     * @return void
     */
    public function testArea(): void
    {
        $area = self::$area;

        $this->assertInstanceOf(Area::class, $area);
        $relationList = $area->getRelations();

        $this->assertInstanceOf(\MusicBrainz\Relation\RelationList\AreaRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToAreaList::class, $relationList->getAreaRelations());
        $this->assertInstanceOf(RelationToEventList::class, $relationList->getEventRelations());
        $this->assertInstanceOf(RelationToInstrumentList::class, $relationList->getInstrumentRelations());
        $this->assertInstanceOf(RelationToReleaseList::class, $relationList->getReleaseRelations());
        $this->assertInstanceOf(RelationToRecordingList::class, $relationList->getRecordingRelations());
        $this->assertInstanceOf(RelationToUrlList::class, $relationList->getUrlRelations());

        /** @todo Test remeining properties of the work relation */
        $this->assertInstanceOf(RelationToWorkList::class, $relationList->getWorkRelations());
        $workRelation = $relationList->getWorkRelations()[0];
        $this->assertInstanceOf(RelationToWork::class, $workRelation);
        $this->assertInstanceOf(Type::class, $workRelation->getRelationType());

        $work = $workRelation->getWork();
        $this->assertInstanceOf(Work::class, $workRelation->getWork());
        $this->assertInstanceOf(Title::class, $work->getTitle());
        $this->assertEquals('Messe F-Dur, BWV 233: I. Coro "Kyrie eleison"', $work->getTitle());
        $this->assertInstanceOf(MBID::class, $work->getMBID());
        $this->assertEquals('002264b6-3d08-32cd-a4ee-add401c607e7', $work->getMBID());
        $this->assertInstanceOf(LanguageList::class, $work->getLanguages());
        $this->assertCount(2, $work->getLanguages());
        $this->assertInstanceOf(Language::class, $work->getLanguages()[0]);
        $this->assertEquals('lat', $work->getLanguages()[0]);
    }
}
