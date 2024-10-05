<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Browse;

use MusicBrainz\Filter\Browse\Relation\Entity\ReleaseRelation;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Supplement\Browse\ReleaseFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Page\ReleaseListPage;
use MusicBrainz\Value\Release;

use function count;

/**
 * Unit tests for the browse release request
 */
class ReleaseTest extends ApiTestCase
{
    /**
     * Test instance of the artist list
     *
     * @var Release[]|ReleaseListPage
     */
    private static ReleaseListPage $releaseListPage;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$releaseListPage)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'release',
            [
                'fmt'    => 'json',
                'limit'  => 25,
                'offset' => 0,
                'label'  => '47e718e1-7ee4-460c-b1cc-1192a841c6e5',
                'inc'    => 'annotation+artist-credits+discids+isrcs+labels+media+recordings+release-groups',
            ],
            'Browse/Release.json'
        );

        /** Performing the test */
        $releaseRelation = new ReleaseRelation();
        $releaseRelation->label(new MBID('47e718e1-7ee4-460c-b1cc-1192a841c6e5'));

        $releaseFields = (new ReleaseFields())
            ->includeReleaseGroups()
            ->includeRecordings()
            ->includeMedia()
            ->includeDiscIds()
            ->includeAnnotation()
            ->includeArtistCredits()
            ->includeIsrcs()
            ->includeLabels();

        self::$releaseListPage = $this->musicBrainz->api()->browse()->release($releaseRelation, $releaseFields, new PageFilter());
    }

    /**
     * Checks the release list.
     *
     * @return void
     */
    public function testReleaseListPage(): void
    {
        $releaseListPage = self::$releaseListPage;

        $this->assertInstanceOf(ReleaseListPage::class, $releaseListPage);
        $this->assertSame(25, count($releaseListPage));

        $release = $releaseListPage[0];

        $this->assertInstanceOf(Release::class, $release);
    }
}
