<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Browse;

use MusicBrainz\Filter\Browse\Relation\Entity\CollectionRelation;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Collection;
use MusicBrainz\Value\CollectionName;
use MusicBrainz\Value\Count;
use MusicBrainz\Value\EditorName;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Page\CollectionListPage;

use function count;

/**
 * Unit tests for the browse collection request
 */
class CollectionTest extends ApiTestCase
{
    /**
     * Test instance of the artist list
     *
     * @var Collection[]|CollectionListPage
     */
    private static CollectionListPage $collectionListPage;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$collectionListPage)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'collection',
            [
                'fmt'    => 'json',
                'limit'  => 25,
                'offset' => 0,
                'artist' => 'c3aeb863-7b26-4388-94e8-5a240f2be21b',
            ],
            'Browse/Collection.json'
        );

        /** Performing the test */
        $collectionRelation = new CollectionRelation();
        $collectionRelation->artist(new MBID('c3aeb863-7b26-4388-94e8-5a240f2be21b'));

        self::$collectionListPage = $this->musicBrainz->api()->browse()->collection($collectionRelation, new PageFilter());
    }

    /**
     * Checks the collection list.
     *
     * @return void
     */
    public function testCollectionListPage(): void
    {
        $collectionListPage = self::$collectionListPage;

        $this->assertInstanceOf(CollectionListPage::class, $collectionListPage);
        $this->assertSame(1, count($collectionListPage));

        $collection = $collectionListPage[0];

        $this->assertInstanceOf(Collection::class, $collection);

        $this->assertInstanceOf(Count::class, $collectionListPage->getOffset());
        $this->assertSame(0, $collectionListPage->getOffset()->getNumber());
        $this->assertInstanceOf(Count::class, $collectionListPage->getCount());
        $this->assertSame(1, $collectionListPage->getCount()->getNumber());

        /** @see testCollection() */
    }

    /**
     * Checks the collection.
     *
     * @return void
     */
    public function testCollection(): void
    {
        $collection = self::$collectionListPage[0];

        $this->assertInstanceOf(Collection::class, $collection);

        $this->assertInstanceOf(MBID::class, $collection->getMBID());
        $this->assertEquals('f27d2f80-0aff-4a0f-a8c3-296226073b20', $collection->getMBID());

        $this->assertInstanceOf(EntityType::class, $collection->getEntityType());
        $this->assertEquals((string) new EntityType(EntityType::ARTIST), $collection->getEntityType());

        $this->assertInstanceOf(EditorName::class, $collection->getEditorName());
        $this->assertEquals('XenosEleatikos', $collection->getEditorName());

        $this->assertInstanceOf(CollectionName::class, $collection->getCollectionName());
        $this->assertEquals('My favourite artists', $collection->getCollectionName());

        $this->assertInstanceOf(Count::class, $collection->getCount());
        $this->assertSame(2, $collection->getCount()->getNumber());
    }
}
