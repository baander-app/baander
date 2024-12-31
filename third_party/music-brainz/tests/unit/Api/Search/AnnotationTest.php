<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Search;

use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Filter\Search\AnnotationFilter;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\Annotation;
use MusicBrainz\Value\AnnotationText;
use MusicBrainz\Value\EntityType;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Name;
use MusicBrainz\Value\Page\SearchResult\AnnotationListPage;
use MusicBrainz\Value\SearchResult;

use function count;

/**
 * Unit tests for the search API.
 */
class AnnotationTest extends ApiTestCase
{
    /**
     * Test instance of the annotation list
     *
     * @var SearchResult\Annotation[]|AnnotationListPage
     */
    private static AnnotationListPage $annotationList;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$annotationList)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'annotation/',
            [
                'limit'  => 5,
                'offset' => 0,
                'fmt'    => 'json',
                'query'  => 'text:awesome',
            ],
            'Search/Annotation.json'
        );

        /** Performing the test */
        $annotationFilter = new AnnotationFilter();
        $annotationFilter->addAnnotationText(new AnnotationText('awesome'));
        $pageFilter = new PageFilter(0, 5);

        self::$annotationList = $this->musicBrainz->api()->search()->annotation($annotationFilter, $pageFilter);
    }

    public function testPage(): void
    {
        $annotationList = self::$annotationList;

        $this->assertInstanceOf(AnnotationListPage::class, $annotationList);
        $this->assertSame(5, count($annotationList));
        $this->assertSame(68, $annotationList->getCount()->getNumber());
        $this->assertSame(0, $annotationList->getOffset()->getNumber());
        $this->assertSame('2017/07/09 11:49:48', (string) $annotationList->getCreationTime());

        $searchResult = $annotationList[0];
        $this->assertInstanceOf(SearchResult\Annotation::class, $searchResult);
        $this->assertSame(100, $searchResult->getScore()->getNumber());
    }

    /**
     * Checks the annotation list.
     *
     * @return void
     */
    public function testAnnotaion(): void
    {
        $annotation = self::$annotationList[0]->getAnnotation();
        $this->assertInstanceOf(Annotation::class, $annotation);

        $this->assertInstanceOf(MBID::class, $annotation->getMBID());
        $this->assertEquals('6370e01e-4fd4-49c4-858b-6f1df7c7fe91', $annotation->getMBID());

        $this->assertInstanceOf(EntityType::class, $annotation->getEntityType());
        $this->assertEquals(EntityType::RELEASE, $annotation->getEntityType());

        $this->assertInstanceOf(Name::class, $annotation->getName());
        $this->assertEquals('Echo Ricochet', $annotation->getName());
    }
}
