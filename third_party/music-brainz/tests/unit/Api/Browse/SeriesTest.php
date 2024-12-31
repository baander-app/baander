<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Browse;

use MusicBrainz\Filter\Browse\Relation\Entity\SeriesRelation;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Supplement\Browse\SeriesFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Page\SeriesListPage;
use MusicBrainz\Value\Series;

use function count;

/**
 * Unit tests for the browse series request
 */
class SeriesTest extends ApiTestCase
{
    /**
     * Test instance of the artist list
     *
     * @var Series[]|SeriesListPage
     */
    private static SeriesListPage $seriesListPage;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$seriesListPage)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'series',
            [
                'fmt'        => 'json',
                'limit'      => 25,
                'offset'     => 0,
                'collection' => 'a2a93eef-8545-4d84-b3f0-67e8054be5db',
                'inc'        => 'aliases+annotation+tags+user-tags',
            ],
            'Browse/Series.json'
        );

        /** Performing the test */
        $seriesRelation = new SeriesRelation();
        $seriesRelation->collection(new MBID('a2a93eef-8545-4d84-b3f0-67e8054be5db'));

        $seriesFields = (new SeriesFields())
            ->includeAliases()
            ->includeAnnotation()
            ->includeTags()
            ->includeUserTags();

        self::$seriesListPage = $this->musicBrainz->api()->browse()->series($seriesRelation, $seriesFields, new PageFilter());
    }

    /**
     * Checks the series list.
     *
     * @return void
     */
    public function testSeriesListPage(): void
    {
        $seriesListPage = self::$seriesListPage;

        $this->assertInstanceOf(SeriesListPage::class, $seriesListPage);
        $this->assertSame(2, count($seriesListPage));

        $series = $seriesListPage[0];

        $this->assertInstanceOf(Series::class, $series);
    }
}
