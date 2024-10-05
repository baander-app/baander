<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Browse;

use MusicBrainz\Filter\Browse\Relation\Entity\InstrumentRelation;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Supplement\Browse\InstrumentFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Alias;
use MusicBrainz\Value\AliasList;
use MusicBrainz\Value\AliasType;
use MusicBrainz\Value\Count;
use MusicBrainz\Value\Date;
use MusicBrainz\Value\Ended;
use MusicBrainz\Value\Instrument;
use MusicBrainz\Value\InstrumentName;
use MusicBrainz\Value\InstrumentType;
use MusicBrainz\Value\LocaleCode;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Name;
use MusicBrainz\Value\Page\InstrumentListPage;
use MusicBrainz\Value\PrimaryNameFlag;
use MusicBrainz\Value\SortName;
use MusicBrainz\Value\Tag;
use MusicBrainz\Value\TagList;

use function count;

/**
 * Unit tests for the browse instrument request
 */
class InstrumentTest extends ApiTestCase
{
    /**
     * Test instance of the instrument list
     *
     * @var Instrument[]|InstrumentListPage
     */
    private static InstrumentListPage $instrumentListPage;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$instrumentListPage)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'instrument',
            [
                'fmt'        => 'json',
                'limit'      => 25,
                'offset'     => 0,
                'collection' => '60db380a-ef1a-4cf7-9abd-6fa26f401558',
                'inc'        => 'aliases+annotation+tags+user-tags',
            ],
            'Browse/Instrument.json'
        );

        /** Performing the test */
        $instrumentRelation = (new InstrumentRelation());
        $instrumentRelation->collection(new MBID('60db380a-ef1a-4cf7-9abd-6fa26f401558'));

        $instrumentFields = (new InstrumentFields())
            ->includeAnnotation()
            ->includeAliases()
            ->includeUserTags()
            ->includeTags();

        self::$instrumentListPage = $this->musicBrainz->api()->browse()->instrument($instrumentRelation, $instrumentFields, new PageFilter());
    }

    /**
     * Checks the instrument list.
     *
     * @return void
     */
    public function testInstrumentListPage(): void
    {
        $instrumentListPage = self::$instrumentListPage;

        $this->assertInstanceOf(InstrumentListPage::class, $instrumentListPage);
        $this->assertSame(3, count($instrumentListPage));

        $instrument = $instrumentListPage[0];

        $this->assertInstanceOf(Instrument::class, $instrument);
    }

    public function testInstrument(): void
    {
        $instrument = self::$instrumentListPage[0];

        $this->assertInstanceOf(Instrument::class, $instrument);
        $this->assertInstanceOf(InstrumentName::class, $instrument->getInstrumentName());
        $this->assertEquals('guitar', $instrument->getInstrumentName());
        $this->assertInstanceOf(MBID::class, $instrument->getMBID());
        $this->assertEquals('63021302-86cd-4aee-80df-2270d54f4978', $instrument->getMBID());
        $this->assertInstanceOf(InstrumentType::class, $instrument->getInstrumentType());
        $this->assertEquals((string) new InstrumentType(InstrumentType::STRING_INSTRUMENT), $instrument->getInstrumentType());
        $this->assertInstanceOf(MBID::class, $instrument->getInstrumentType()->getMBID());
        $this->assertEquals('cc00f97f-cf3d-3ae2-9163-041cb1a0d726', $instrument->getInstrumentType()->getMBID());
        /** @todo test description */
        /** @todo test disambiguation */
        /** @todo test annotation */
        /** @todo test user tags */
    }

    public function testAliases(): void
    {
        $aliases = self::$instrumentListPage[0]->getAliases();

        $this->assertInstanceOf(AliasList::class, $aliases);
        $this->assertCount(27, $aliases);

        $alias = $aliases[0];

        $this->assertInstanceOf(Alias::class, $alias);
        $this->assertInstanceOf(Name::class, $alias->getName());
        $this->assertEquals('Gitaro', $alias->getName());

        $this->assertInstanceOf(SortName::class, $alias->getSortName());
        $this->assertEquals('Gitaro', $alias->getSortName());

        $this->assertInstanceOf(AliasType::class, $alias->getAliasType());
        $this->assertEquals('Instrument name', $alias->getAliasType());
        $this->assertEquals('2322fc94-fbf3-3c09-b23c-aa5ec8d14fcd', $alias->getAliasType()->getMbid());

        $this->assertInstanceOf(LocaleCode::class, $alias->getLocaleCode());
        $this->assertEquals('eo', $alias->getLocaleCode());

        $this->assertInstanceOf(PrimaryNameFlag::class, $alias->getPrimaryName());
        $this->assertTrue($alias->getPrimaryName()->isPrimaryName());

        /** @todo Test with given dates */
        $this->assertInstanceOf(Date::class, $alias->getBeginDate());
        $this->assertNull($alias->getBeginDate()->getDateTime());
        $this->assertInstanceOf(Date::class, $alias->getEndDate());
        $this->assertNull($alias->getEndDate()->getDateTime());
        $this->assertInstanceOf(Ended::class, $alias->getEnded());
        $this->assertFalse($alias->getEnded()->isEnded());
    }

    public function testFlags(): void
    {
        $tags = self::$instrumentListPage[0]->getTags();

        $this->assertInstanceOf(TagList::class, $tags);
        $this->assertCount(26, $tags);

        $tag = $tags[1];

        $this->assertInstanceOf(Tag::class, $tag);
        $this->assertInstanceOf(Count::class, $tag->getCount());
        $this->assertSame(1, $tag->getCount()->getNumber());
        $this->assertInstanceOf(Name::class, $tag->getName());
        $this->assertEquals('art rock', $tag->getName());
    }
}
