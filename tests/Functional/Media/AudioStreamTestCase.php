<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Auth\Domain\Model\User;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Shared\Application\Http\BaanderHeader;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Application\DTO\AudioRenditionClaim;
use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\DTO\AudioRenditionRuntimeSnapshot;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Infrastructure\Transcode\AudioRenditionExecutor;
use Psr\Log\NullLogger;

/**
 * HTTP format, cache and access coverage with an explicitly invoked worker executor.
 * Durable process handoff uses independent connections in the integration suite.
 */
abstract class AudioStreamTestCase extends TestCase
{
    protected string $libraryDirectory;
    protected string $cacheRoot;
    protected string $sourceFile;
    protected PublicId $trackId;
    protected Uuid $libraryId;

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
        $this->installAudioWorkerFixture();
    }

    private function installAudioWorkerFixture(): void
    {
        // DAMA keeps HTTP fixtures in an uncommitted transaction. Exercise the real
        // encoder here; separate integration tests prove independent DB ownership.
        /** @var array<string, AudioRenditionRuntimeSnapshot> $rows */
        $rows = [];
        $runtime = $this->createStub(AudioRenditionRuntimePort::class);
        $runtime->method('find')->willReturnCallback(static function (string $key) use (&$rows): ?AudioRenditionRuntimeSnapshot { return $rows[$key] ?? null; });
        $runtime->method('renew')->willReturn(true);
        $runtime->method('start')->willReturnCallback(static function (AudioRenditionClaim $claim, string $partial, string $final) use (&$rows): bool {
            $rows[$claim->runtime->key] = new AudioRenditionRuntimeSnapshot($claim->runtime->key, $claim->runtime->input, 'running', 1, $partial, $final, null, true);
            return true;
        });
        $runtime->method('complete')->willReturnCallback(static function (AudioRenditionClaim $claim) use (&$rows): bool {
            $row = $rows[$claim->runtime->key];
            $rows[$row->key] = new AudioRenditionRuntimeSnapshot($row->key, $row->input, 'complete', 1, $row->partialPath, $row->finalPath, null);
            return true;
        });
        $runtime->method('fail')->willReturnCallback(static function (AudioRenditionClaim $claim, string $error) use (&$rows): bool {
            $row = $rows[$claim->runtime->key];
            $rows[$row->key] = new AudioRenditionRuntimeSnapshot($row->key, $row->input, 'failed', 1, $row->partialPath, $row->finalPath, $error);
            return true;
        });
        $runtime->method('withOwnership')->willReturnCallback(static function (AudioRenditionClaim $claim, callable $operation): bool {
            $operation();
            return true;
        });
        $executor = new AudioRenditionExecutor($runtime, new NullLogger(), getenv('FFMPEG_PATH') ?: 'ffmpeg');
        $runtime->method('admit')->willReturnCallback(static function (AudioRenditionInput $input) use (&$rows, $executor): AudioRenditionRuntimeSnapshot {
            if (!isset($rows[$input->key()])) {
                $row = new AudioRenditionRuntimeSnapshot($input->key(), $input, 'pending', 1, null, null, null, true);
                $rows[$row->key] = $row;
                $executor->run(new AudioRenditionClaim($row, new MediaWorkerIdentity('baander.app:test', str_repeat('a', 32), 'media', 1), 'fixture'), static fn (): bool => false);
            }
            return $rows[$input->key()];
        });
        self::getContainer()->set(AudioRenditionRuntimePort::class, $runtime);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->libraryDirectory);
        $this->removeTree($this->cacheRoot);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $server
     */
    protected function request(User $user, string $query, array $server = []): \Symfony\Component\BrowserKit\Response
    {
        $this->client->request('GET', $this->uri($query), [], [], [
            BaanderHeader::TestUserId->serverKey() => $user->getId()->toString(),
        ] + $server);

        return $this->client->getInternalResponse();
    }

    protected function uri(string $query): string
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
    protected function probe(string $audio): array
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
    protected function cachedFiles(): array
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
