<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;

/**
 * On-the-fly audio transcoding through the track stream endpoint, with transcoding on.
 *
 * Uses the real encoder (FFmpeg) against a generated FLAC source. Outside the
 * Swoole runtime the encode runs in-process before the response streams, so
 * these tests prove the formats, caching and access rules; the progressive
 * hand-off while an encode is still running is covered by the unit tests and
 * the Swoole runtime check.
 */
final class AudioTranscodeStreamTest extends AudioStreamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->get(SystemSettingStoreInterface::class)->save([TranscodeSettingDefinitions::ENABLED => true]);
    }

    public function testMp3At192KbpsStreamsTheEncodeThenServesTheCachedRenditionWithRanges(): void
    {
        $admin = $this->createAdminUser();

        $first = $this->request($admin, 'format=mp3&bitrate=192000');
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('audio/mpeg', $first->getHeader('Content-Type'));
        self::assertSame('none', $first->getHeader('Accept-Ranges'));
        $body = $first->getContent();
        self::assertNotSame('', $body);
        self::assertSame(['codec' => 'mp3', 'bitrate' => 192000], $this->probe($body));

        $cached = $this->request($admin, 'format=mp3&bitrate=192000');
        self::assertSame(200, $cached->getStatusCode());
        self::assertSame('audio/mpeg', $cached->getHeader('Content-Type'));
        self::assertSame('bytes', $cached->getHeader('Accept-Ranges'));
        self::assertSame($body, $cached->getContent());

        $range = $this->request($admin, 'format=mp3&bitrate=192000', ['HTTP_RANGE' => 'bytes=100-199']);
        self::assertSame(206, $range->getStatusCode());
        self::assertSame(sprintf('bytes 100-199/%d', strlen($body)), $range->getHeader('Content-Range'));
        self::assertSame(substr($body, 100, 100), $range->getContent());
    }

    public function testEachSupportedFormatProducesItsCodec(): void
    {
        $admin = $this->createAdminUser();

        $opus = $this->request($admin, 'format=opus&bitrate=96000');
        self::assertSame(200, $opus->getStatusCode());
        self::assertSame('audio/ogg', $opus->getHeader('Content-Type'));
        self::assertSame('opus', $this->probe($opus->getContent())['codec']);

        $aac = $this->request($admin, 'format=aac');
        self::assertSame(200, $aac->getStatusCode());
        self::assertSame('audio/aac', $aac->getHeader('Content-Type'));
        self::assertSame('aac', $this->probe($aac->getContent())['codec']);
    }

    public function testOriginalStreamIsUnchangedWithoutTranscodeParameters(): void
    {
        $admin = $this->createAdminUser();

        $original = $this->request($admin, '');
        self::assertSame(200, $original->getStatusCode());
        self::assertSame('audio/flac', $original->getHeader('Content-Type'));
        self::assertSame('bytes', $original->getHeader('Accept-Ranges'));
        self::assertSame(file_get_contents($this->sourceFile), $original->getContent());
        self::assertSame([], $this->cachedFiles());
    }

    public function testListenerWithoutAccessGetsTheSameResponseAsForTheOriginal(): void
    {
        // Anonymous first: a later authenticated request would leave a session behind.
        $this->client->request('GET', $this->uri(''));
        $anonymousOriginal = $this->client->getInternalResponse();
        $this->client->request('GET', $this->uri('format=mp3&bitrate=192000'));
        $anonymousTranscoded = $this->client->getInternalResponse();
        self::assertSame(401, $anonymousOriginal->getStatusCode());
        self::assertSame(401, $anonymousTranscoded->getStatusCode());
        self::assertSame($anonymousOriginal->getContent(), $anonymousTranscoded->getContent());

        $member = $this->createTestUser();
        $unrelated = $this->createTestUser();
        self::getContainer()->get(LibraryAccessPortInterface::class)->grant($member->getId(), $this->libraryId);

        $original = $this->request($unrelated, '');
        $transcoded = $this->request($unrelated, 'format=mp3&bitrate=192000');
        self::assertSame(403, $original->getStatusCode());
        self::assertSame(403, $transcoded->getStatusCode());
        self::assertSame($original->getContent(), $transcoded->getContent());
        self::assertSame([], $this->cachedFiles());
    }

    public function testFailedEncodeReturnsAnErrorAndLeavesNoPartialRendition(): void
    {
        self::assertNotFalse(file_put_contents($this->sourceFile, str_repeat('not audio', 64)));
        $admin = $this->createAdminUser();

        $failed = $this->request($admin, 'format=mp3&bitrate=192000');

        self::assertSame(500, $failed->getStatusCode());
        $error = json_decode($failed->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($error);
        self::assertSame('Audio transcoding failed.', $error['error']['message'] ?? null);
        self::assertSame([], array_values(array_filter(
            $this->cachedFiles(),
            static fn (string $file): bool => !str_ends_with($file, '.lock'),
        )));
    }

    public function testUnsupportedFormatAndBitrateWithoutFormatAreRejected(): void
    {
        $admin = $this->createAdminUser();

        self::assertSame(400, $this->request($admin, 'format=wav')->getStatusCode());
        self::assertSame(400, $this->request($admin, 'format=mp3&bitrate=fast')->getStatusCode());
        self::assertSame(400, $this->request($admin, 'bitrate=192000')->getStatusCode());
        self::assertSame([], $this->cachedFiles());
    }
}
