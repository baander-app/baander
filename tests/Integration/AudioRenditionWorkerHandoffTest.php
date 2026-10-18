<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Transcode\Application\Exception\AudioRenditionFailedException;
use App\Transcode\Application\CommandHandler\SweepTranscodeCacheHandler;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Infrastructure\Doctrine\AudioRenditionRuntime;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Transcode\AudioRenditionCache;
use App\Tests\Support\NativeExecutionFixture;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

final class AudioRenditionWorkerHandoffTest extends TestCase
{
    use MediaRuntimePersistenceHarness {
        setUp as private prepareDatabase;
        tearDown as private cleanDatabase;
    }

    private string $root;
    private string $source;
    private ?Process $workerProcess = null;
    private ?NativeExecutionFixture $native = null;

    protected function setUp(): void
    {
        $this->prepareDatabase();
        $this->root = sys_get_temp_dir() . '/audio-handoff-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->source = $this->root . '/source.flac';
        $source = new Process(['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3', '-ac', '2', '-ar', '44100', '-c:a', 'flac', $this->source]);
        self::assertSame(0, $source->run(), $source->getErrorOutput());
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            try {
                $this->workerProcess?->stop(.2);
            } finally {
                $this->native?->close();
            }
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->root);
        }
        $this->cleanDatabase();
    }

    public function testIndependentWorkerDiscoversCommittedAdmissionWithoutAWakeupAndPublishesGrowingBytes(): void
    {
        $this->startWorker(.3);
        $cache = $this->cache();
        $rendition = $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertFalse($rendition->isComplete());
        $encoded = implode('', iterator_to_array($rendition->chunks(), false));
        self::assertNotSame('', $encoded);
        self::assertSame(0, $this->workerProcess->wait(), $this->workerProcess->getErrorOutput());
        $cached = $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertTrue($cached->isComplete());
        self::assertSame($encoded, file_get_contents($cached->path));
        self::assertSame(1, (int) $this->first->fetchOne('SELECT count(*) FROM audio_rendition_runtime'));
        self::assertSame('complete', $this->first->fetchOne('SELECT state FROM audio_rendition_runtime'));
    }

    public function testPinnedReaderRejectsLeaseLossAndExpiredWorkerCannotPublishACompletedFile(): void
    {
        $this->startWorker(2);
        $rendition = $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        $chunks = $rendition->chunks();
        self::assertInstanceOf(\Generator::class, $chunks);
        self::assertNotSame('', $chunks->current());
        $final = (string) $this->first->fetchOne('SELECT final_path FROM audio_rendition_runtime');
        $this->first->executeStatement("UPDATE audio_rendition_runtime SET lease_expires_at = clock_timestamp() - interval '1 second'");
        try {
            $chunks->next();
            self::fail('Reader must reject lost ownership.');
        } catch (AudioRenditionFailedException $error) {
            self::assertStringContainsString('lost ownership', $error->getMessage());
        }
        self::assertSame(0, $this->workerProcess->wait(), $this->workerProcess->getErrorOutput());
        self::assertFileDoesNotExist($final);
        self::assertFileDoesNotExist($final . '.part');
        self::assertCount(1, (new AudioRenditionRuntime($this->second))->pending());
        $this->startWorker(.1);
        $deadline = microtime(true) + 2;
        while ((int) $this->first->fetchOne('SELECT generation FROM audio_rendition_runtime') < 2 && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $recovered = $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertNotSame('', implode('', iterator_to_array($recovered->chunks(), false)));
        self::assertSame(0, $this->workerProcess->wait(), $this->workerProcess->getErrorOutput());
        self::assertSame(2, (int) $this->first->fetchOne('SELECT generation FROM audio_rendition_runtime'));
    }

    public function testEvictedCompletedAudioRebuildsFromTheSameSourceInANewWorkerGeneration(): void
    {
        $cache = $this->cache();
        $this->startWorker(.1);
        $original = $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        $encoded = $original->isComplete() ? file_get_contents($original->path) : implode('', iterator_to_array($original->chunks(), false));
        self::assertIsString($encoded);
        self::assertNotSame('', $encoded);
        self::assertSame(0, $this->workerProcess->wait(), $this->workerProcess->getErrorOutput());
        $key = $this->first->fetchOne('SELECT rendition_key FROM audio_rendition_runtime');
        $final = $this->first->fetchOne('SELECT final_path FROM audio_rendition_runtime');
        self::assertIsString($final);
        $storage = new TranscodeFileStorage(new SegmentFileResolver($this->root . '/cache'));
        $sweep = new SweepTranscodeCacheHandler($storage, $this->createStub(TranscodeJobPortInterface::class), $this->createStub(TranscodeSessionPortInterface::class));
        self::assertSame(['audio-renditions/track'], $sweep->sweep(['max_gb' => 0.0])->deletedDirectories);
        self::assertFileDoesNotExist($final);

        $this->startWorker(.1);
        $rebuilt = $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertSame($encoded, $rebuilt->isComplete() ? file_get_contents($rebuilt->path) : implode('', iterator_to_array($rebuilt->chunks(), false)));
        self::assertSame(0, $this->workerProcess->wait(), $this->workerProcess->getErrorOutput());
        self::assertSame($key, $this->first->fetchOne('SELECT rendition_key FROM audio_rendition_runtime'));
        self::assertSame(2, (int) $this->first->fetchOne('SELECT generation FROM audio_rendition_runtime'));
        self::assertSame(1, (int) $this->first->fetchOne('SELECT count(*) FROM audio_rendition_runtime'));
        self::assertSame('complete', $this->first->fetchOne('SELECT state FROM audio_rendition_runtime'));
    }

    private function cache(): AudioRenditionCache
    {
        return new AudioRenditionCache(new SegmentFileResolver($this->root . '/cache'), new AudioRenditionRuntime($this->first), new NullLogger(), pollIntervalSeconds: .005, startTimeoutSeconds: 3);
    }

    private function startWorker(float $delay): void
    {
        $this->native?->close();
        $this->native = null;
        $this->native = new NativeExecutionFixture('baander.app:test', str_repeat('b', 32));
        $this->workerProcess = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/Media/audio-rendition-worker.php', $this->schema, (string) $delay], env: [
            'BAANDER_NATIVE_SOCKET' => $this->native->socketPath(),
            'BAANDER_WORKER_NAMESPACE' => 'baander.app:test',
            'BAANDER_WORKER_BOOT_ID' => str_repeat('b', 32),
            'BAANDER_WORKER_LEASE_EPOCH' => '1',
        ]);
        $this->workerProcess->setTimeout(8);
        $this->workerProcess->start();
    }
}
