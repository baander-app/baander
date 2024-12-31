<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Search;

use AskLucy\Lucene;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\PlaceFilter;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Page\SearchResult\PlaceListPage;
use MusicBrainz\Value\SearchResult;

use function count;

/**
 * Unit tests for the place search
 */
class PlaceTest extends ApiTestCase
{
    /**
     * Test instance of the place list
     *
     * @var SearchResult\Place[]|PlaceListPage
     */
    private static PlaceListPage $placeList;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$placeList)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'place/',
            [
                'fmt'    => 'json',
                'query'  => 'Leipzig',
                'limit'  => 100,
                'offset' => 0,
            ],
            'Search/Place.json'
        );

        /** Performing the test */
        $placeFilter = new PlaceFilter();
        $placeFilter->add(Lucene::term('Leipzig'));

        self::$placeList = $this->musicBrainz->api()->search()->place($placeFilter, new PageFilter(0, 100));
    }

    public function testPage(): void
    {
        $placeList = self::$placeList;

        $this->assertInstanceOf(PlaceListPage::class, $placeList);
        $this->assertSame(25, count($placeList));
        $this->assertSame(78, $placeList->getCount()->getNumber());
        $this->assertSame(0, $placeList->getOffset()->getNumber());
        $this->assertSame('2019/03/17 12:21:30', (string) $placeList->getCreationTime());
    }

    public function testSearchResult(): void
    {
        $searchResult = self::$placeList[4];
        $this->assertInstanceOf(SearchResult\Place::class, $searchResult);
        $this->assertSame(100, $searchResult->getScore()->getNumber());
    }
}
