<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Browse;

use MusicBrainz\Filter\Browse\Relation\Entity\ReleaseGroupRelation;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Supplement\Browse\ReleaseGroupFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Page\ReleaseGroupListPage;
use MusicBrainz\Value\ReleaseGroup;

use function count;

/**
 * Unit tests for the browse release group request
 */
class ReleaseGroupTest extends ApiTestCase
{
    /**
     * Test instance of the artist list
     *
     * @var ReleaseGroup[]|ReleaseGroupListPage
     */
    private static ReleaseGroupListPage $releaseGroupListPage;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$releaseGroupListPage)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'release-group',
            [
                'fmt'    => 'json',
                'limit'  => 25,
                'offset' => 0,
                'artist' => 'c3aeb863-7b26-4388-94e8-5a240f2be21b',
                'inc'    => 'annotation+artist-credits+tags+user-ratings+user-tags',
            ],
            'Browse/ReleaseGroup.json'
        );

        /** Performing the test */
        $releaseGroupRelation = new ReleaseGroupRelation();
        $releaseGroupRelation->artist(new MBID('c3aeb863-7b26-4388-94e8-5a240f2be21b'));

        $releaseGroupFields = (new ReleaseGroupFields())
            ->includeAnnotation()
            ->includeArtistCredits()
            ->includeUserRatings()
            ->includeTags()
            ->includeUserRatings()
            ->includeUserTags();

        self::$releaseGroupListPage = $this->musicBrainz->api()->browse()->releaseGroup($releaseGroupRelation, $releaseGroupFields, new PageFilter());
    }

    /**
     * Checks the release group list.
     *
     * @return void
     */
    public function testReleaseGroupListPage(): void
    {
        $releaseGroupListPage = self::$releaseGroupListPage;

        $this->assertInstanceOf(ReleaseGroupListPage::class, $releaseGroupListPage);
        $this->assertSame(25, count($releaseGroupListPage));

        $releaseGroup = $releaseGroupListPage[0];

        $this->assertInstanceOf(ReleaseGroup::class, $releaseGroup);
    }
}
