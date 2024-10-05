<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Lookup;

use MusicBrainz\Relation\Target\RelationList\RelationToArtistList;
use MusicBrainz\Relation\Target\RelationList\RelationToEventList;
use MusicBrainz\Relation\Target\RelationList\RelationToInstrumentList;
use MusicBrainz\Relation\Target\RelationList\RelationToLabelList;
use MusicBrainz\Relation\Target\RelationList\RelationToPlaceList;
use MusicBrainz\Relation\Target\RelationList\RelationToRecordingList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseGroupList;
use MusicBrainz\Relation\Target\RelationList\RelationToReleaseList;
use MusicBrainz\Relation\Target\RelationList\RelationToSeriesList;
use MusicBrainz\Relation\Target\RelationList\RelationToUrlList;
use MusicBrainz\Relation\Target\RelationList\RelationToWorkList;
use MusicBrainz\Supplement\Lookup\ArtistFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Area;
use MusicBrainz\Value\Artist;
use MusicBrainz\Value\Country;
use MusicBrainz\Value\DataQuality;
use MusicBrainz\Value\Date;
use MusicBrainz\Value\Disc;
use MusicBrainz\Value\DiscId;
use MusicBrainz\Value\DiscList;
use MusicBrainz\Value\Format;
use MusicBrainz\Value\ISO31661Code;
use MusicBrainz\Value\ISO31661CodeList;
use MusicBrainz\Value\Language;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\MediaList;
use MusicBrainz\Value\Medium;
use MusicBrainz\Value\Name;
use MusicBrainz\Value\Release;
use MusicBrainz\Value\ReleaseEvent;
use MusicBrainz\Value\ReleaseEventList;
use MusicBrainz\Value\ReleaseGroupList;
use MusicBrainz\Value\ReleaseList;
use MusicBrainz\Value\ReleaseStatus;
use MusicBrainz\Value\Script;
use MusicBrainz\Value\SectorOffsetList;
use MusicBrainz\Value\Sectors;
use MusicBrainz\Value\TextRepresentation;
use MusicBrainz\Value\Title;

/**
 * Unit tests for the lookup API.
 */
class ArtistTest extends ApiTestCase
{
    /**
     * Test instance of the artist
     *
     * @var Artist
     */
    private static Artist $artist;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$artist)) {
            return;
        }

        parent::setUp();

        /**
         * Setting up the mock object of the abstract HTTP adapter
         */
        $this->expectApiCall(
            'artist/4aae17a7-9f0c-487b-b60e-f8eafb410b1d',
            [
                'fmt' => 'json',
                'inc' => 'aliases+annotation+discids+media+ratings+recordings+release-groups+releases+tags+user-ratings+user-tags+various-artists+works+area-rels+artist-rels+event-rels+instrument-rels+label-rels+place-rels+recording-rels+release-group-rels+release-rels+series-rels+url-rels+work-rels',
            ],
            'Lookup/Artist.json'
        );

        /**
         * Performing the test
         */
        $fields = (new ArtistFields())
            ->includeAliases()
            ->includeAnnotation()
            ->includeDiscIds()
            ->includeMedia()
            ->includeRatings()
            ->includeRecordings()
            ->includeReleaseGroups()
            ->includeReleases()
            ->includeTags()
            ->includeUserRatings()
            ->includeUserTags()
            ->includeVariousArtists()
            ->includeWorks()
            // Relations
            ->includeAreaRelations()
            ->includeArtistRelations()
            ->includeEventRelations()
            ->includeInstrumentRelations()
            ->includeLabelRelations()
            ->includePlaceRelations()
            ->includeReleaseGroupRelations()
            ->includeRecordingRelations()
            ->includeReleaseRelations()
            ->includeSeriesRelations()
            ->includeUrlRelations()
            ->includeWorkRelations();

        self::$artist = $this->musicBrainz->api()->lookup()->artist(
            new MBID('4aae17a7-9f0c-487b-b60e-f8eafb410b1d'),
            $fields
        );
    }

    /**
     * Checks the artist.
     *
     * @return void
     */
    public function testArtist(): void
    {
        $artist = self::$artist;

        $this->assertInstanceOf(Artist::class, $artist);

        $this->assertInstanceOf(Country::class, $artist->getCountry());

        $this->assertEquals('AU', $artist->getCountry());

        /** @see testReleases() */

        /** @see testRelations() */
    }

    /**
     * Checks the artist's releases.
     *
     * @return void
     */
    public function testReleases(): void
    {
        $releases = self::$artist->getReleases();

        $this->assertInstanceOf(ReleaseList::class, $releases);
        $this->assertCount(25, $releases);

        $release = $releases[0];

        $this->assertInstanceOf(Release::class, $release);

        /** @see testReleaseEvents() */

        $releaseStatus = $release->getReleaseStatus();
        $this->assertInstanceOf(ReleaseStatus::class, $releaseStatus);
        $this->assertEquals('Official', $releaseStatus);
        $this->assertInstanceOf(MBID::class, $releaseStatus->getMBID());
        $this->assertEquals('4e304316-386d-3409-af2e-78857eec5cfe', $releaseStatus->getMBID());

        /** @todo Test release aliases */

        /** @todo Test packaging */

        $quality = $release->getQuality();
        $this->assertInstanceOf(DataQuality::class, $quality);
        $this->assertEquals('normal', $quality);

        /** @todo Test barcode */

        $country = $release->getCountry();
        $this->assertInstanceOf(Country::class, $country);
        $this->assertEquals('GB', $country);

        $MBID = $release->getMBID();
        $this->assertInstanceOf(MBID::class, $MBID);
        $this->assertEquals('06aa6fbf-5018-3648-8bc6-0aac2ef01ea4', $MBID);

        /** @todo Test release group */

        /** @todo Test release disambiguation */

        $title = $release->getTitle();
        $this->assertInstanceOf(Title::class, $title);
        $this->assertEquals('The Bridge: A Tribute to Neil Young', $title);

        /** @todo Test release annotation */

        $date = $release->getDate();
        $this->assertInstanceOf(Date::class, $date);
        $this->assertEquals('1989', $date);

        /** @see testTextRepresentation() */

        /** @see testMedia() */
    }

    public function testReleaseGroups(): void
    {
        $releaseGroups = self::$artist->getReleaseGroups();

        $this->assertInstanceOf(ReleaseGroupList::class, $releaseGroups);
    }

    /**
     * Checks the release events.
     *
     * @@return void
     */
    public function testReleaseEvents(): void
    {
        $releaseEvents = self::$artist->getReleases()[0]->getReleaseEvents();
        $this->assertInstanceOf(ReleaseEventList::class, $releaseEvents);
        $this->assertCount(1, $releaseEvents);
        $releaseEvent = $releaseEvents[0];

        $area = $releaseEvent->getArea();
        $this->assertInstanceOf(ReleaseEvent::class, $releaseEvent);
        $this->assertInstanceOf(Area::class, $area);
        $iso31661Codes = $area->getISO31661Codes();
        $this->assertInstanceOf(ISO31661CodeList::class, $iso31661Codes);
        $this->assertCount(1, $iso31661Codes);
        $iso31661Code = $iso31661Codes[0];
        $this->assertInstanceOf(ISO31661Code::class, $iso31661Code);
        $this->assertEquals('GB', $iso31661Code);
        $this->assertInstanceOf(MBID::class, $area->getMBID());
        $this->assertEquals('8a754a16-0027-3a29-b6d7-2b40ea0481ed', $area->getMBID());

        /** @todo Test disambiguation */

        /** @todo Test sort name */

        $this->assertInstanceOf(Name::class, $area->getName());
        $this->assertEquals('United Kingdom', $area->getName());

        $this->assertInstanceOf(Date::class, $releaseEvent->getDate());
        $this->assertEquals('1989', $releaseEvent->getDate());
    }

    /**
     * Checks the text representation of a release.
     *
     * @@return void
     */
    public function testTextRepresentation(): void
    {
        $textRepresentation = self::$artist->getReleases()[0]->getTextRepresentation();
        $this->assertInstanceOf(TextRepresentation::class, $textRepresentation);
        $this->assertEquals('Latn (eng)', $textRepresentation);
        $this->assertInstanceOf(Language::class, $textRepresentation->getLanguage());
        $this->assertEquals('eng', $textRepresentation->getLanguage());
        $this->assertInstanceOf(Script::class, $textRepresentation->getScript());
        $this->assertEquals('Latn', $textRepresentation->getScript());
    }

    /**
     * Checks the media of a release.
     *
     * @@return void
     */
    public function testMedia(): void
    {
        $media = self::$artist->getReleases()[0]->getMedia();
        $this->assertInstanceOf(MediaList::class, $media);

        $medium = $media[0];
        $this->assertInstanceOf(Medium::class, $medium);
        $this->assertInstanceOf(Format::class, $medium->getFormat());
        $this->assertEquals('CD', $medium->getFormat());
        $this->assertInstanceOf(MBID::class, $medium->getFormat()->getMBID());
        $this->assertEquals('9712d52a-4509-3d4b-a1a2-67c88c643e31', $medium->getFormat()->getMBID());

        /** @todo Test medium position */

        /** @todo Test tracks count */

        /** @todo Test medium title */

        /** @see testDiscs() */
    }

    /**
     * Checks the discs of a medium.
     *
     * @return void
     */
    public function testDiscs(): void
    {
        $discs = self::$artist->getReleases()[0]->getMedia()[0]->getDiscs();
        $this->assertInstanceOf(DiscList::class, $discs);

        $disc = $discs[0];
        $this->assertInstanceOf(Disc::class, $disc);
        $this->assertInstanceOf(DiscId::class, $disc->getDiscId());
        $this->assertEquals('jR3tHyJp8_mAuHwe02_0cdux8GI-', $disc->getDiscId());
        $this->assertInstanceOf(Sectors::class, $disc->getSectors());
        $this->assertEquals('254935', $disc->getSectors());
        $this->assertInstanceOf(SectorOffsetList::class, $disc->getSectorOffsets());
        $this->assertCount(14, $disc->getSectorOffsets());
        $this->assertInstanceOf(Sectors::class, $disc->getSectorOffsets()[0]);
        $this->assertEquals('150', $disc->getSectorOffsets()[0]);
        $this->assertInstanceOf(Sectors::class, $disc->getSectorOffsets()[1]);
        $this->assertEquals('13042', $disc->getSectorOffsets()[1]);
    }

    public function testRelations(): void
    {
        $relationList = self::$artist->getRelations();

        $this->assertInstanceOf(\MusicBrainz\Relation\RelationList\ArtistRelationList::class, $relationList);
        $this->assertInstanceOf(RelationToArtistList::class, $relationList->getArtistRelations());
        $this->assertInstanceOf(RelationToEventList::class, $relationList->getEventRelations());
        $this->assertInstanceOf(RelationToInstrumentList::class, $relationList->getInstrumentRelations());
        $this->assertInstanceOf(RelationToLabelList::class, $relationList->getLabelRelations());
        $this->assertInstanceOf(RelationToPlaceList::class, $relationList->getPlaceRelations());
        $this->assertInstanceOf(RelationToRecordingList::class, $relationList->getRecordingRelations());
        $this->assertInstanceOf(RelationToReleaseGroupList::class, $relationList->getReleaseGroupRelations());
        $this->assertInstanceOf(RelationToReleaseList::class, $relationList->getReleaseRelations());
        $this->assertInstanceOf(RelationToSeriesList::class, $relationList->getSeriesRelations());
        $this->assertInstanceOf(RelationToUrlList::class, $relationList->getUrlRelations());
        $this->assertInstanceOf(RelationToWorkList::class, $relationList->getWorkRelations());
    }
}
