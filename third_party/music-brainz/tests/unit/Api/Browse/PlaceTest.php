<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Browse;

use MusicBrainz\Filter\Browse\Relation\Entity\PlaceRelation;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Supplement\Browse\PlaceFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Page\PlaceListPage;
use MusicBrainz\Value\Place;

use function count;

/**
 * Unit tests for the browse place request
 */
class PlaceTest extends ApiTestCase
{
    /**
     * Test instance of the artist list
     *
     * @var Place[]|PlaceListPage
     */
    private static PlaceListPage $placeListPage;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$placeListPage)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'place',
            [
                'fmt'    => 'json',
                'limit'  => 25,
                'offset' => 0,
                'area'   => '20619e36-fca8-4499-bcc8-be01a3ea3e41',
                'inc'    => 'aliases+annotation+tags+user-tags',
            ],
            'Browse/Place.json'
        );

        /** Performing the test */
        $placeRelation = new PlaceRelation();
        $placeRelation->area(new MBID('20619e36-fca8-4499-bcc8-be01a3ea3e41'));

        $placeFields = (new PlaceFields())
            ->includeAliases()
            ->includeAnnotation()
            ->includeTags()
            ->includeUserTags();

        self::$placeListPage = $this->musicBrainz->api()->browse()->place($placeRelation, $placeFields, new PageFilter());
    }

    /**
     * Checks the place list.
     *
     * @return void
     */
    public function testPlaceListPage(): void
    {
        $placeListPage = self::$placeListPage;

        $this->assertInstanceOf(PlaceListPage::class, $placeListPage);
        $this->assertSame(25, count($placeListPage));

        $place = $placeListPage[0];

        $this->assertInstanceOf(Place::class, $place);
    }
}
