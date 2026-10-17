<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\DTO\AudioRenditionRuntimeSnapshot;
use App\Transcode\Application\Exception\AudioRenditionFailedException;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use App\Transcode\Infrastructure\Transcode\AudioRenditionCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AudioRenditionCacheTest extends TestCase
{
    private string $root;
    private string $source;
    private AudioRenditionRuntimePort&\PHPUnit\Framework\MockObject\Stub $runtime;
    private AudioRenditionInput $input;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/audio-cache-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->source = $this->root . '/source.flac';
        file_put_contents($this->source, 'source');
        $this->runtime = $this->createStub(AudioRenditionRuntimePort::class);
        $this->runtime->method('admit')->willReturnCallback(function (AudioRenditionInput $input): AudioRenditionRuntimeSnapshot {
            $this->input = $input;
            return $this->snapshot('pending');
        });
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testCompletedWorkerOutputIsReturnedAsARangeCapableFile(): void
    {
        $this->runtime->method('find')->willReturnCallback(function (): AudioRenditionRuntimeSnapshot {
            $this->write('finished');
            return $this->snapshot('complete');
        });
        $rendition = $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertTrue($rendition->isComplete());
        self::assertSame('finished', file_get_contents($rendition->path));
    }

    public function testGrowingReaderRetainsItsOpenFileAcrossFinalRename(): void
    {
        $this->runtime->method('find')->willReturnCallback(function (): AudioRenditionRuntimeSnapshot {
            if (!isset($this->input)) {
                throw new \LogicException('Not admitted.');
            }
            if (!is_file($this->path()) && !is_file($this->path() . '.part')) {
                $this->write('first-', true);
            }
            return $this->snapshot(is_file($this->path()) ? 'complete' : 'running');
        });
        $rendition = $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertFalse($rendition->isComplete());
        $chunks = $rendition->chunks();
        self::assertInstanceOf(\Generator::class, $chunks);
        self::assertSame('first-', $chunks->current());
        file_put_contents($this->path() . '.part', 'second', FILE_APPEND);
        rename($this->path() . '.part', $this->path());
        $chunks->next();
        self::assertSame('second', $chunks->current());
        $chunks->next();
        self::assertFalse($chunks->valid());
    }

    public function testPinnedReaderRejectsAReplacementBeforeYieldingMoreBytes(): void
    {
        $generation = 1;
        $this->runtime->method('find')->willReturnCallback(function () use (&$generation): AudioRenditionRuntimeSnapshot {
            $this->write('original', true);
            return $this->snapshot('running', $generation);
        });
        $chunks = $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000)->chunks();
        self::assertInstanceOf(\Generator::class, $chunks);
        self::assertSame('original', $chunks->current());
        file_put_contents($this->path() . '.part', 'must not escape', FILE_APPEND);
        $generation = 2;
        $this->expectException(AudioRenditionFailedException::class);
        $chunks->next();
    }

    public function testExpiredOwnershipCannotExposeExistingPartialBytes(): void
    {
        $this->runtime->method('find')->willReturnCallback(function (): AudioRenditionRuntimeSnapshot {
            $this->write('stale', true);
            return $this->snapshot('running', live: false);
        });
        $this->expectException(AudioRenditionFailedException::class);
        $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
    }

    public function testPendingAdmissionWithoutAWorkerFailsWithinTheStartupDeadline(): void
    {
        $this->runtime->method('find')->willReturnCallback(fn (): AudioRenditionRuntimeSnapshot => $this->snapshot('pending'));
        $start = microtime(true);
        try {
            $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
            self::fail('Missing worker must fail.');
        } catch (AudioRenditionFailedException $error) {
            self::assertStringContainsString('did not start in time', $error->getMessage());
            self::assertLessThan(.5, microtime(true) - $start);
        }
    }

    public function testSourceChangesSelectAnotherDurableIdentity(): void
    {
        $keys = [];
        $this->runtime->method('find')->willReturnCallback(function () use (&$keys): AudioRenditionRuntimeSnapshot {
            $keys[] = $this->input->key();
            $this->write('finished');
            return $this->snapshot('complete');
        });
        $cache = $this->cache();
        $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        file_put_contents($this->source, 'new source contents');
        $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertCount(2, array_unique($keys));
    }

    public function testFailedRuntimeReturnsItsEncodingErrorBeforeStreaming(): void
    {
        $this->runtime->method('find')->willReturnCallback(fn (): AudioRenditionRuntimeSnapshot => new AudioRenditionRuntimeSnapshot($this->input->key(), $this->input, 'failed', 1, null, null, 'invalid input'));
        $this->expectException(AudioRenditionFailedException::class);
        $this->expectExceptionMessage('invalid input');
        $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
    }

    public function testMissingCompletedFileFailsInsteadOfReturningAnEmptySuccess(): void
    {
        $this->runtime->method('find')->willReturnCallback(fn (): AudioRenditionRuntimeSnapshot => $this->snapshot('complete'));
        $this->expectException(AudioRenditionFailedException::class);
        $this->expectExceptionMessage('completed rendition is missing');
        $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
    }

    private function cache(): AudioRenditionCache
    {
        return new AudioRenditionCache(new SegmentFileResolver($this->root), $this->runtime, new NullLogger(), pollIntervalSeconds: .001, startTimeoutSeconds: .01);
    }

    private function snapshot(string $state, int $generation = 1, bool $live = true): AudioRenditionRuntimeSnapshot
    {
        $path = $this->path($generation);
        return new AudioRenditionRuntimeSnapshot($this->input->key(), $this->input, $state, $generation, $state === 'pending' ? null : $path . '.part', $state === 'pending' ? null : $path, null, $live);
    }

    private function path(int $generation = 1): string
    {
        return $this->input->outputDirectory . '/generation-' . $generation . '/' . $this->input->sourceSignature;
    }

    private function write(string $bytes, bool $partial = false): void
    {
        if (!is_dir(dirname($this->path()))) {
            mkdir(dirname($this->path()), 0700, true);
        }
        file_put_contents($this->path() . ($partial ? '.part' : ''), $bytes);
    }
}
