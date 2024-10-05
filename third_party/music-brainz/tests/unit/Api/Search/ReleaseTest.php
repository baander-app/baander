<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Search;

use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\ReleaseFilter;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Area;
use MusicBrainz\Value\Artist;
use MusicBrainz\Value\ArtistCredit;
use MusicBrainz\Value\ArtistCreditList;
use MusicBrainz\Value\ASIN;
use MusicBrainz\Value\Country;
use MusicBrainz\Value\Date;
use MusicBrainz\Value\Format;
use MusicBrainz\Value\ISO31661Code;
use MusicBrainz\Value\ISO31661CodeList;
use MusicBrainz\Value\Language;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\MediaList;
use MusicBrainz\Value\Medium;
use MusicBrainz\Value\Name;
use MusicBrainz\Value\Packaging;
use MusicBrainz\Value\Page\SearchResult\ReleaseListPage;
use MusicBrainz\Value\Release;
use MusicBrainz\Value\ReleaseEvent;
use MusicBrainz\Value\ReleaseEventList;
use MusicBrainz\Value\ReleaseGroup;
use MusicBrainz\Value\ReleaseStatus;
use MusicBrainz\Value\ReleaseType;
use MusicBrainz\Value\ReleaseTypeList;
use MusicBrainz\Value\Script;
use MusicBrainz\Value\SearchResult;
use MusicBrainz\Value\SortName;
use MusicBrainz\Value\TextRepresentation;
use MusicBrainz\Value\Title;

use function count;

/**
 * Unit tests for the release search
 */
class ReleaseTest extends ApiTestCase
{
    /**
     * Test instance of the release list
     *
     * @var SearchResult\Release[]|ReleaseListPage
     */
    private static ReleaseListPage $releaseList;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$releaseList)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'release/',
            [
                'fmt'    => 'json',
                'query'  => 'arid:172e1f1a-504d-4488-b053-6344ba63e6d0',
                'limit'  => 25,
                'offset' => 0,
            ],
            'Search/Release.json'
        );

        /** Performing the test */
        $releaseFilter = new ReleaseFilter();
        $releaseFilter->addArtistId(new MBID('172e1f1a-504d-4488-b053-6344ba63e6d0'));

        self::$releaseList = $this->musicBrainz->api()->search()->release($releaseFilter, new PageFilter());
    }

    public function testPage(): void
    {
        $releaseList = self::$releaseList;

        $this->assertInstanceOf(ReleaseListPage::class, $releaseList);
        $this->assertSame(25, count($releaseList));
        $this->assertSame(372, $releaseList->getCount()->getNumber());
        $this->assertSame(0, $releaseList->getOffset()->getNumber());
        $this->assertSame('2019/02/27 17:59:27', (string) $releaseList->getCreationTime());
    }

    public function testSearchResult(): void
    {
        $searchResult = self::$releaseList[4];
        $this->assertInstanceOf(SearchResult\Release::class, $searchResult);
        $this->assertSame(100, $searchResult->getScore()->getNumber());
    }

    /**
     * Tests, if Search::release() properly converts the given JSON response into a domain model and returns it.
     *
     * @return void
     */
    public function testRelease(): void
    {
        $release = self::$releaseList[0]->getRelease();
        $this->assertInstanceOf(Release::class, $release);

        $this->assertInstanceOf(MBID::class, $release->getMBID());
        $this->assertEquals('f627eea8-6862-3eec-96fe-458543738a76', $release->getMBID());

        $this->assertInstanceOf(Title::class, $release->getTitle());
        $this->assertEquals('Live Seeds', $release->getTitle());

        $this->assertInstanceOf(ReleaseStatus::class, $release->getReleaseStatus());
        $this->assertEquals(ReleaseStatus::OFFICIAL, $release->getReleaseStatus());

        $this->assertInstanceOf(Packaging::class, $release->getPackaging());
        $this->assertEquals(Packaging::JEWEL_CASE, $release->getPackaging());

        $this->assertInstanceOf(TextRepresentation::class, $release->getTextRepresentation());
        $this->assertEquals('Latn (eng)', $release->getTextRepresentation());
        $this->assertInstanceOf(Language::class, $release->getTextRepresentation()->getLanguage());
        $this->assertEquals('eng', $release->getTextRepresentation()->getLanguage());
        $this->assertInstanceOf(Script::class, $release->getTextRepresentation()->getScript());
        $this->assertEquals('Latn', $release->getTextRepresentation()->getScript());

        $this->assertInstanceOf(ArtistCreditList::class, $release->getArtistCredits());
        $this->assertCount(1, $release->getArtistCredits());

        $artistCredit = $release->getArtistCredits()[0];
        $this->assertInstanceOf(ArtistCredit::class, $artistCredit);
        $this->assertInstanceOf(Artist::class, $artistCredit->getArtist());
        $this->assertEquals('Nick Cave & The Bad Seeds', $artistCredit->getArtist());
        $this->assertInstanceOf(Name::class, $artistCredit->getArtist()->getArtistName());
        $this->assertEquals('Nick Cave & The Bad Seeds', $artistCredit->getArtist()->getArtistName());
        $this->assertInstanceOf(SortName::class, $artistCredit->getArtist()->getSortName());
        $this->assertEquals('Cave, Nick, & The Bad Seeds', $artistCredit->getArtist()->getSortName());

        $releaseGroup = $release->getReleaseGroup();
        $this->assertInstanceOf(ReleaseGroup::class, $releaseGroup);
        $this->assertInstanceOf(MBID::class, $releaseGroup->getMBID());
        $this->assertEquals('ed4bb6ce-ace6-3841-931b-7f8f0bcfe806', $releaseGroup->getMBID());
        $this->assertInstanceOf(ReleaseType::class, $releaseGroup->getPrimaryReleaseType());
        $this->assertEquals('Album', $releaseGroup->getPrimaryReleaseType());
        $this->assertInstanceOf(ReleaseTypeList::class, $releaseGroup->getSecondaryReleaseTypes());
        $this->assertCount(1, $releaseGroup->getSecondaryReleaseTypes());
        $this->assertContainsOnlyInstancesOf(ReleaseType::class, $releaseGroup->getSecondaryReleaseTypes());
        $this->assertEquals('Live', $releaseGroup->getSecondaryReleaseTypes()[0]);

        $this->assertInstanceOf(Date::class, $release->getDate());
        $this->assertEquals('1996-08-05', $release->getDate());
        $this->assertInstanceOf(Country::class, $release->getCountry());
        $this->assertEquals('GB', $release->getCountry());

        $this->assertInstanceOf(ReleaseEventList::class, $release->getReleaseEvents());
        $this->assertContainsOnlyInstancesOf(ReleaseEvent::class, $release->getReleaseEvents());

        $releaseEvent = $release->getReleaseEvents()[0];
        $this->assertInstanceOf(ReleaseEvent::class, $releaseEvent);
        $this->assertInstanceOf(Date::class, $releaseEvent->getDate());
        $this->assertEquals('1996-08-05', $releaseEvent->getDate());
        $this->assertInstanceOf(Area::class, $releaseEvent->getArea());
        $this->assertEquals('United Kingdom', $releaseEvent->getArea());
        $this->assertInstanceOf(Name::class, $releaseEvent->getArea()->getName());
        $this->assertEquals('United Kingdom', $releaseEvent->getArea()->getName());
        $this->assertInstanceOf(SortName::class, $releaseEvent->getArea()->getSortName());
        $this->assertEquals('United Kingdom', $releaseEvent->getArea()->getSortName());
        $this->assertInstanceOf(ISO31661CodeList::class, $releaseEvent->getArea()->getISO31661Codes());
        $this->assertContainsOnlyInstancesOf(ISO31661Code::class, $releaseEvent->getArea()->getISO31661Codes());
        $this->assertEquals('GB', $releaseEvent->getArea()->getISO31661Codes()[0]);

        $this->assertInstanceOf(ASIN::class, $release->getAsin());
        $this->assertEquals('B000003Z4N', $release->getAsin());

        /** @todo Test Label Info */
        /** @todo Test Track count */

        $this->assertInstanceOf(MediaList::class, $release->getMedia());
        $this->assertContainsOnlyInstancesOf(Medium::class, $release->getMedia());

        $medium = $release->getMedia()[0];
        $this->assertInstanceOf(Format::class, $medium->getFormat());
        $this->assertEquals('CD', $medium->getFormat());

        /** @todo Test Dist count */
        /** @todo Test Track count */
    }
}
