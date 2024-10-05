<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Search;

use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\ArtistFilter;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Artist;
use MusicBrainz\Value\ArtistType;
use MusicBrainz\Value\Date;
use MusicBrainz\Value\Gender;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Name;
use MusicBrainz\Value\Page\SearchResult\ArtistListPage;
use MusicBrainz\Value\SearchResult;
use MusicBrainz\Value\SortName;

use function count;

/**
 * Unit tests for the search API.
 */
class ArtistTest extends ApiTestCase
{
    /**
     * Test instance of the artist list
     *
     * @var SearchResult\Artist[]|ArtistListPage
     */
    private static ArtistListPage $artistList;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$artistList)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'artist/',
            [
                'fmt'    => 'json',
                'query'  => 'artist:Waite',
                'limit'  => 25,
                'offset' => 0,
            ],
            'Search/Artist.json'
        );

        /** Performing the test */
        $artistFilter = new ArtistFilter();
        $artistFilter->addArtistNameWithoutAccents(new Name('Waite'));

        self::$artistList = $this->musicBrainz->api()->search()->artist($artistFilter, new PageFilter());
    }

    public function testPage(): void
    {
        $artistList = self::$artistList;

        $this->assertInstanceOf(ArtistListPage::class, $artistList);
        $this->assertSame(21, count($artistList));
        $this->assertSame(21, $artistList->getCount()->getNumber());
        $this->assertSame(0, $artistList->getOffset()->getNumber());
        $this->assertSame('2018/12/08 19:13:37', (string) $artistList->getCreationTime());
    }

    public function testSearchResult(): void
    {
        $searchResult = self::$artistList[4];
        $this->assertInstanceOf(SearchResult\Artist::class, $searchResult);
        $this->assertSame(84, $searchResult->getScore()->getNumber());
    }

    /**
     * Tests, if Search::artist() properly converts the given JSON response into a domain model and returns it.
     *
     * @return void
     */
    public function testArtist(): void
    {
        $artist = self::$artistList[4]->getArtist();
        $this->assertInstanceOf(Artist::class, $artist);

        $this->assertInstanceOf(ArtistType::class, $artist->getArtistType());
        $this->assertEquals(ArtistType::PERSON, $artist->getArtistType());
        $this->assertInstanceOf(MBID::class, $artist->getArtistType()->getMBID());
        $this->assertEquals('b6e035f4-3ce9-331c-97df-83397230b0df', $artist->getArtistType()->getMBID());
        $this->assertSame('b856b4ae-5c73-4476-a61c-f1fddc573a4e', (string) $artist->getMBID());

        $this->assertInstanceOf(Name::class, $artist->getArtistName());
        $this->assertEquals('Xanthe Waite', $artist->getArtistName());

        $this->assertInstanceOf(SortName::class, $artist->getSortName());
        $this->assertEquals('Waite, Xanthe', $artist->getSortName());

        $this->assertInstanceOf(Gender::class, $artist->getGender());
        $this->assertEquals(Gender::FEMALE, $artist->getGender());

        /**
         * "life-span": {
         *     "ended": null
         * }
         */
        $this->assertInstanceOf(Date::class, $artist->getLifeSpan()->getBeginDate());
        $this->assertEmpty((string) $artist->getLifeSpan()->getBeginDate());
        $this->assertInstanceOf(Date::class, $artist->getLifeSpan()->getEndDate());
        $this->assertEmpty((string) $artist->getLifeSpan()->getEndDate());
        $this->assertFalse($artist->getLifeSpan()->getEnded()->isEnded());

        /**
         * "life-span": {
         *     "begin": "1963-06-16",
         *     "end": "1993-02-18",
         *     "ended": true
         * }
         *
         * @var Artist $artist
         */
        $artist = self::$artistList[16]->getArtist();
        $this->assertInstanceOf(Date::class, $artist->getLifeSpan()->getBeginDate());
        $this->assertEquals('1963-06-16', $artist->getLifeSpan()->getBeginDate());
        $this->assertInstanceOf(Date::class, $artist->getLifeSpan()->getEndDate());
        $this->assertEquals('1993-02-18', $artist->getLifeSpan()->getEndDate());
        $this->assertTrue($artist->getLifeSpan()->getEnded()->isEnded());

        /** @todo Test country and area */
    }
}
