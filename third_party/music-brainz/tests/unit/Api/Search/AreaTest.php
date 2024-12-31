<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Search;

use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\AreaFilter;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\AliasType;
use MusicBrainz\Value\Area;
use MusicBrainz\Value\AreaType;
use MusicBrainz\Value\Date;
use MusicBrainz\Value\LifeSpan;
use MusicBrainz\Value\LocaleCode;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Name;
use MusicBrainz\Value\Page\SearchResult\AreaListPage;
use MusicBrainz\Value\SearchResult;
use MusicBrainz\Value\SortName;

use function count;

/**
 * Unit tests for the search API.
 */
class AreaTest extends ApiTestCase
{
    /**
     * Test instance of the area list
     *
     * @var SearchResult\Area[]|AreaListPage
     */
    private static AreaListPage $areaList;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$areaList)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'area/',
            [
                'limit'  => 5,
                'offset' => 0,
                'fmt'    => 'json',
                'query' => 'area:Leipzig',
            ],
            'Search/Area.json'
        );

        /** Performing the test */
        $areaFilter = new AreaFilter();
        $areaFilter->addAreaName(new Name('Leipzig'));
        $pageFilter = new PageFilter(0, 5);

        self::$areaList = $this->musicBrainz->api()->search()->area($areaFilter, $pageFilter);
    }

    public function testPage(): void
    {
        $areaList = self::$areaList;

        $this->assertInstanceOf(AreaListPage::class, $areaList);
        $this->assertSame(2, count($areaList));
        $this->assertSame(2, $areaList->getCount()->getNumber());
        $this->assertSame(0, $areaList->getOffset()->getNumber());
        $this->assertSame('2017/07/09 17:54:44', (string) $areaList->getCreationTime());
    }

    public function testSearchResult(): void
    {
        $searchResult = self::$areaList[0];
        $this->assertInstanceOf(SearchResult\Area::class, $searchResult);
        $this->assertSame(100, $searchResult->getScore()->getNumber());
    }

    /**
     * Tests, if Search::area() properly converts the given JSON response into a domain model and returns it.
     *
     * @return void
     */
    public function testArea(): void
    {
        $area = self::$areaList[0]->getArea();
        $this->assertInstanceOf(Area::class, $area);

        $this->assertInstanceOf(AreaType::class, $area->getAreaType());
        $this->assertSame(AreaType::CITY, (string) $area->getAreaType());

        /** @todo Test not-empty life spans */
        $this->assertInstanceOf(LifeSpan::class, $area->getLifeSpan());
        $this->assertInstanceOf(Date::class, $area->getLifeSpan()->getBeginDate());
        $this->assertEmpty((string) $area->getLifeSpan()->getBeginDate());
        $this->assertInstanceOf(Date::class, $area->getLifeSpan()->getEndDate());
        $this->assertEmpty((string) $area->getLifeSpan()->getEndDate());
        $this->assertFalse($area->getLifeSpan()->getEnded()->isEnded());

        $this->assertInstanceOf(MBID::class, $area->getMBID());
        $this->assertEquals('20619e36-fca8-4499-bcc8-be01a3ea3e41', $area->getMBID());

        $this->assertInstanceOf(Name::class, $area->getName());
        $this->assertEquals('Leipzig', $area->getName());

        $this->assertInstanceOf(SortName::class, $area->getSortName());
        $this->assertEquals('Leipzig', $area->getSortName());

        $alias = $area->getAliases()[0];
        $this->assertEquals(AliasType::AREA_NAME, $alias->getAliasType());

        /** @todo Test not-empty dates */
        $this->assertInstanceOf(Date::class, $alias->getBeginDate());
        $this->assertEmpty((string) $alias->getBeginDate());
        $this->assertInstanceOf(Date::class, $alias->getEndDate());
        $this->assertEmpty((string) $alias->getEndDate());
        $this->assertInstanceOf(LocaleCode::class, $alias->getLocaleCode());
        $this->assertEquals('ja', $alias->getLocaleCode());
        $this->assertInstanceOf(Name::class, $alias->getName());
        $this->assertEquals('ライプツィヒ', $alias->getName());
        $this->assertTrue($alias->getPrimaryName()->isPrimaryName());
        $this->assertInstanceOf(SortName::class, $alias->getSortName());
        $this->assertEquals('ライプツィヒ', $alias->getSortName());
    }
}
