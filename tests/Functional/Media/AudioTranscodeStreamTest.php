<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Auth\Domain\Model\User;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;

/**
 * On-the-fly audio transcoding through the track stream endpoint.
 *
 * Uses the real encoder (FFmpeg) against a generated FLAC source. Outside the
 * Swoole runtime the encode runs in-process before the response streams, so
 * these tests prove the formats, caching and access rules; the progressive
 * hand-off while an encode is still running is covered by the unit tests and
 * the Swoole runtime check.
 */
final class AudioTranscodeStreamTest extends TestCase
{
    private string $libraryDirectory;
    private string $cacheRoot;
    private string $sourceFile;
    private PublicId $trackId;
    private Uuid $libraryId;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(8));
        $this->libraryDirectory = sys_get_temp_dir() . '/baander-transcode-library-' . $suffix;
        $this->cacheRoot = sys_get_temp_dir() . '/baander-transcode-cache-' . $suffix;
        self::assertTrue(mkdir($this->libraryDirectory, 0700));
        self::assertTrue(mkdir($this->cacheRoot, 0700));
        $this->sourceFile = $this->libraryDirectory . '/track.flac';
        $this->generateSource($this->sourceFile);

        $this->trackId = new PublicId();
        $library = new LibraryEntity(
            name: 'Audio transcode test',
            slug: 'audio-transcode-' . $suffix,
            path: $this->libraryDirectory,
            type: 'music',
            filesystemType: 'local',
        );
        $this->entityManager->persist($library);
        $this->entityManager->flush();
        $this->libraryId = $library->getId();

        $stream = $this->createStub(StreamPortInterface::class);
        $stream->method('getLibraryIdForTrack')->willReturn($this->libraryId);
        $stream->method('resolveTrackPath')->willReturnCallback(fn (): string => $this->sourceFile);
        $stream->method('getTrackMetadata')->willReturnCallback(fn (): TrackStreamMetadata => new TrackStreamMetadata(
            publicId: $this->trackId->toString(),
            filename: basename($this->sourceFile),
            filePath: $this->sourceFile,
            mimeType: 'audio/flac',
            size: (int) filesize($this->sourceFile),
            codec: 'flac',
            bitrate: null,
            sampleRate: 44100,
            channels: 2,
            length: 3,
        ));
        self::getContainer()->set(StreamPortInterface::class, $stream);
        self::getContainer()->set(SegmentFileResolver::class, new SegmentFileResolver($this->cacheRoot));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->libraryDirectory);
        $this->removeTree($this->cacheRoot);

        parent::tearDown();
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

    /**
     * @param array<string, string> $server
     */
    private function request(User $user, string $query, array $server = []): \Symfony\Component\BrowserKit\Response
    {
        $this->client->request('GET', $this->uri($query), [], [], [
            BaanderHeader::TestUserId->serverKey() => $user->getId()->toString(),
        ] + $server);

        return $this->client->getInternalResponse();
    }

    private function uri(string $query): string
    {
        return '/api/stream/track?id=' . $this->trackId->toString() . ($query === '' ? '' : '&' . $query);
    }

    private function generateSource(string $path): void
    {
        $command = sprintf(
            'ffmpeg -nostdin -hide_banner -loglevel error -y -f lavfi -i %s -ac 2 -ar 44100 -c:a flac %s 2>&1',
            escapeshellarg('sine=frequency=440:duration=3'),
            escapeshellarg($path),
        );
        exec($command, $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    /** @return array{codec: string, bitrate: int} */
    private function probe(string $audio): array
    {
        $file = tempnam(sys_get_temp_dir(), 'baander-probe-');
        self::assertIsString($file);
        try {
            self::assertNotFalse(file_put_contents($file, $audio));
            exec(sprintf(
                'ffprobe -v error -select_streams a:0 -show_entries stream=codec_name,bit_rate:format=bit_rate -of json %s 2>&1',
                escapeshellarg($file),
            ), $output, $code);
            self::assertSame(0, $code, implode("\n", $output));
            $probe = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($probe);
            $stream = $probe['streams'][0] ?? [];

            return [
                'codec' => (string) ($stream['codec_name'] ?? ''),
                'bitrate' => (int) ($stream['bit_rate'] ?? $probe['format']['bit_rate'] ?? 0),
            ];
        } finally {
            unlink($file);
        }
    }

    /** @return list<string> */
    private function cachedFiles(): array
    {
        $files = [];
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->cacheRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            $files[] = substr($entry->getPathname(), strlen($this->cacheRoot) + 1);
        }
        sort($files);

        return $files;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
