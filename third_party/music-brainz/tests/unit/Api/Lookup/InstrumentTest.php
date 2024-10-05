<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\Target\RelationList\RelationToAreaList;
use MusicBrainz\Relation\Target\RelationList\RelationToArtistList;
use MusicBrainz\Relation\Target\RelationList\RelationToInstrumentList;
use MusicBrainz\Relation\Target\RelationList\RelationToLabelList;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Supplement\Lookup\InstrumentFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Instrument;
use MusicBrainz\Value\MBID;

/**
 * Unit tests for the lookup API.
 */
class InstrumentTest extends ApiTestCase
{
    /**
     * Test instance of the artist
     *
     * @var Instrument
     */
    private static Instrument $instrument;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$instrument)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'instrument/7ee8ebf5-3aed-4fc8-8004-49f4a8c45a87',
            [
                'fmt' => 'json',
                    'inc' => 'area-rels+artist-rels+instrument-rels+label-rels+url-rels',
            ],
            'Lookup/Instrument.json'
        );

        /**
         * Performing the test
         */
        $fields = (new InstrumentFields())
            ->includeAreaRelations()
            ->includeArtistRelations()
            ->includeInstrumentRelations()
            ->includeLabelRelations()
            ->includeUrlRelations();

        self::$instrument = $this->musicBrainz->api()->lookup()->instrument(
            new MBID('7ee8ebf5-3aed-4fc8-8004-49f4a8c45a87'),
            $fields
        );
    }

    /**
     * Checks the instrument.
     *
     * @return void
     */
    public function testInstrument(): void
    {
        $artist = self::$instrument;

        $this->assertInstanceOf(Instrument::class, $artist);
    }

    public function testRelations(): void
    {
        $relationList = self::$instrument->getRelations();

        $this->assertInstanceOf(\MusicBrainz\Relation\RelationList\InstrumentRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToAreaList::class, $relationList->getAreaRelations());
        $this->assertInstanceOf(RelationToArtistList::class, $relationList->getArtistRelations());
        $this->assertInstanceOf(RelationToInstrumentList::class, $relationList->getInstrumentRelations());
        $this->assertInstanceOf(RelationToLabelList::class, $relationList->getLabelRelations());
        $this->assertInstanceOf(RelationToUrlList::class, $relationList->getUrlRelations());
    }
}
