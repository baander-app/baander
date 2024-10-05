<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Search;

use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\LabelFilter;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Name;
use MusicBrainz\Value\Page\SearchResult\LabelListPage;
use MusicBrainz\Value\SearchResult;

use function count;

/**
 * Unit tests for the label search
 */
class LabelTest extends ApiTestCase
{
    /**
     * Test instance of the label list
     *
     * @var SearchResult\Label[]|LabelListPage
     */
    private static LabelListPage $labelList;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$labelList)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'label/',
            [
                'fmt'    => 'json',
                'query'  => 'label:"Devil\'s Records"',
                'limit'  => 100,
                'offset' => 0,
            ],
            'Search/Label.json'
        );

        /** Performing the test */
        $labelFilter = new LabelFilter();
        $labelFilter->addLabelNameWithAccents(new Name('Devil\'s Records'));

        self::$labelList = $this->musicBrainz->api()->search()->label($labelFilter, new PageFilter(0, 100));
    }

    public function testPage(): void
    {
        $labelList = self::$labelList;

        $this->assertInstanceOf(LabelListPage::class, $labelList);
        $this->assertSame(25, count($labelList));
        $this->assertSame(25, $labelList->getCount()->getNumber());
        $this->assertSame(0, $labelList->getOffset()->getNumber());
        $this->assertSame('2019/03/16 15:37:56', (string) $labelList->getCreationTime());
    }

    public function testSearchResult(): void
    {
        $searchResult = self::$labelList[4];
        $this->assertInstanceOf(SearchResult\Label::class, $searchResult);
        $this->assertSame(82, $searchResult->getScore()->getNumber());
    }
}
