<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Browse;

use MusicBrainz\Filter\Browse\Relation\Entity\WorkRelation;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Supplement\Browse\WorkFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Page\WorkListPage;
use MusicBrainz\Value\Work;

use function count;

/**
 * Unit tests for the browse work request
 */
class WorkTest extends ApiTestCase
{
    /**
     * Test instance of the artist list
     *
     * @var Work[]|WorkListPage
     */
    private static WorkListPage $workListPage;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$workListPage)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'work',
            [
                'fmt'    => 'json',
                'limit'  => 25,
                'offset' => 0,
                'artist' => 'c3aeb863-7b26-4388-94e8-5a240f2be21b',
                'inc'    => 'aliases+annotation+ratings+tags+user-ratings+user-tags',
            ],
            'Browse/Work.json'
        );

        /** Performing the test */
        $workRelation = new WorkRelation();
        $workRelation->artist(new MBID('c3aeb863-7b26-4388-94e8-5a240f2be21b'));

        $workFields = (new WorkFields())
            ->includeAliases()
            ->includeAnnotation()
            ->includeRatings()
            ->includeUserRatings()
            ->includeTags()
            ->includeUserTags();

        self::$workListPage = $this->musicBrainz->api()->browse()->work($workRelation, $workFields, new PageFilter());
    }

    /**
     * Checks the work list.
     *
     * @return void
     */
    public function testWorkListPage(): void
    {
        $workListPage = self::$workListPage;

        $this->assertInstanceOf(WorkListPage::class, $workListPage);
        $this->assertSame(25, count($workListPage));

        $work = $workListPage[0];

        $this->assertInstanceOf(Work::class, $work);
    }
}
