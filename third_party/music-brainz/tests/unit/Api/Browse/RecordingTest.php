<?php

declare(strict_types=1);

namespace MusicBrainz\Test\Api\Browse;

use MusicBrainz\Filter\Browse\Relation\Entity\RecordingRelation;
use MusicBrainz\Filter\PageFilter;
use MusicBrainz\Supplement\Browse\RecordingFields;
use MusicBrainz\Test\Api\ApiTestCase;
use MusicBrainz\Value\MBID;
use MusicBrainz\Value\Page\RecordingListPage;
use MusicBrainz\Value\Recording;

use function count;

/**
 * Unit tests for the browse recording request
 */
class RecordingTest extends ApiTestCase
{
    /**
     * Test instance of the artist list
     *
     * @var Recording[]|RecordingListPage
     */
    private static RecordingListPage $recordingListPage;

    /**
     * Sets up a mock object of the abstract HTTP adapter and the MusicBrainz API client to be tested.
     *
     * @return void
     */
    public function setUp(): void
    {
        if (isset(self::$recordingListPage)) {
            return;
        }

        parent::setUp();

        /** Setting up the mock object of the abstract HTTP adapter */
        $this->expectApiCall(
            'recording',
            [
                'fmt'    => 'json',
                'limit'  => 25,
                'offset' => 0,
                'artist' => 'c3aeb863-7b26-4388-94e8-5a240f2be21b',
                'inc'    => 'annotation+artist-credits+isrcs+ratings+tags+user-ratings+user-tags',
            ],
            'Browse/Recording.json'
        );

        /** Performing the test */
        $recordingRelation = new RecordingRelation();
        $recordingRelation->artist(new MBID('c3aeb863-7b26-4388-94e8-5a240f2be21b'));

        $recordingFields = (new RecordingFields())
            ->includeAnnotation()
            ->includeArtistCredits()
            ->includeIsrcs()
            ->includeRatings()
            ->includeTags()
            ->includeUserRatings()
            ->includeUserTags();

        self::$recordingListPage = $this->musicBrainz->api()->browse()->recording($recordingRelation, $recordingFields, new PageFilter());
    }

    /**
     * Checks the recording list.
     *
     * @return void
     */
    public function testRecordingListPage(): void
    {
        $recordingListPage = self::$recordingListPage;

        $this->assertInstanceOf(RecordingListPage::class, $recordingListPage);
        $this->assertSame(25, count($recordingListPage));

        $recording = $recordingListPage[0];

        $this->assertInstanceOf(Recording::class, $recording);
    }
}
