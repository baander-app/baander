<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Search;

use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\CdStubFilter;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Page\SearchResult\CdStubListPage;
use MusicBrainz\Value\SearchResult;
use MusicBrainz\Value\Title;

use function count;

/**
 * Unit tests for the cdStub search
 */
class CdStubTest extends ApiTestCase
{
    /**
     * Test instance of the cdStub list
     *
     * @var SearchResult\CdStub[]|CdStubListPage
     */
    private static CdStubListPage $cdStubList;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$cdStubList)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'cdstub/',
            [
                'fmt'    => 'json',
                'query'  => 'title:Doo',
                'limit'  => 100,
                'offset' => 0,
            ],
            'Search/CdStub.json'
        );

        /** Performing the test */
        $cdStubFilter = new CdStubFilter();
        $cdStubFilter->addTitleComment(new Title('Doo'));

        self::$cdStubList = $this->musicBrainz->api()->search()->cdStub($cdStubFilter, new PageFilter(0, 100));
    }

    public function testPage(): void
    {
        $cdStubList = self::$cdStubList;

        $this->assertInstanceOf(CdStubListPage::class, $cdStubList);
        $this->assertSame(25, count($cdStubList));
        $this->assertSame(56, $cdStubList->getCount()->getNumber());
        $this->assertSame(0, $cdStubList->getOffset()->getNumber());
        $this->assertSame('2019/03/16 15:21:14', (string) $cdStubList->getCreationTime());
    }

    public function testSearchResult(): void
    {
        $searchResult = self::$cdStubList[4];
        $this->assertInstanceOf(SearchResult\CdStub::class, $searchResult);
        $this->assertSame(91, $searchResult->getScore()->getNumber());
    }
}
