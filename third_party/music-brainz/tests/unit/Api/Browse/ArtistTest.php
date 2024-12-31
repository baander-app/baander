<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Browse;

use MusicBrainz\Filter\Browse\Relation\Entity\ArtistRelation;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Supplement\Browse\ArtistFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Artist;
use MusicBrainz\Value\ArtistType;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Name;
use MusicBrainz\Value\Page\ArtistListPage;

use function count;

/**
 * Unit tests for the browse artist request
 */
class ArtistTest extends ApiTestCase
{
    /**
     * Test instance of the artist list
     *
     * @var Artist[]|ArtistListPage
     */
    private static ArtistListPage $artistListPage;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$artistListPage)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'artist',
            [
                'fmt'     => 'json',
                'limit'   => 25,
                'offset'  => 0,
                'area'    => '75e398a3-5f3f-4224-9cd8-0fe44715bc95',
                'inc'     => 'aliases+annotation+genres+ratings+tags+user-genres+user-ratings+user-tags',
            ],
            'Browse/Artist.json'
        );

        /** Performing the test */
        $artistRelation = new ArtistRelation();
        $artistRelation->area(new MBID('75e398a3-5f3f-4224-9cd8-0fe44715bc95'));

        $artistFields = (new ArtistFields())
            ->includeAliases()
            ->includeAnnotation()
            ->includeGenres()
            ->includeRatings()
            ->includeTags()
            ->includeUserGenres()
            ->includeUserRatings()
            ->includeUserTags();

        self::$artistListPage = $this->musicBrainz->api()->browse()->artist($artistRelation, $artistFields, new PageFilter());
    }

    /**
     * Checks the artist list.
     *
     * @return void
     */
    public function testArtistListPage(): void
    {
        $artistListPage = self::$artistListPage;

        $this->assertInstanceOf(ArtistListPage::class, $artistListPage);
        $this->assertSame(25, count($artistListPage));

        $artist = $artistListPage[0];

        $this->assertInstanceOf(Artist::class, $artist);
    }

    /**
     * Checks the artist.
     *
     * @return void
     */
    public function testArtist(): void
    {
        $artist = self::$artistListPage[0];

        $this->assertInstanceOf(Artist::class, $artist);

        $this->assertInstanceOf(ArtistType::class, $artist->getArtistType());
        $this->assertEquals('Person', $artist->getArtistType());
        $this->assertInstanceOf(MBID::class, $artist->getArtistType()->getMBID());
        $this->assertEquals('b6e035f4-3ce9-331c-97df-83397230b0df', $artist->getArtistType()->getMBID());

        /** @todo Test disambiguation */
        /** @todo Test ratings */
        /** @todo Test aliases */
        /** @todo Test IPIs */
        /** @todo Test country */

        $this->assertInstanceOf(Name::class, $artist->getArtistName());
        $this->assertEquals('$1 Bin', $artist->getArtistName());

        /** @todo Test ISNIs */
        /** @todo Test lifespan */
        /** @todo Test begin area */
        /** @todo Test end area */
        /** @todo Test annotation */
        /** @todo Test area */
    }
}
