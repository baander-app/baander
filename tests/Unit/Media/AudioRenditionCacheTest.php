<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Application\DTO\AudioRenditionRuntimeSnapshot;
use App\Transcode\Application\Exception\AudioRenditionBusyException;
use App\Transcode\Application\Exception\AudioRenditionFailedException;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Transcode\Domain\ValueObject\AacImplementation;
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

    public function testCopyUsesSelectedStreamWithoutBitrateAndReturnsCompletedMp4(): void
    {
        $this->runtime->method('find')->willReturnCallback(function (): AudioRenditionRuntimeSnapshot {
            $this->write('copied');
            return $this->snapshot('complete');
        });
        $rendition = $this->cache()->openCopy('track', $this->source, hash_file('xxh3', $this->source), 3);
        self::assertSame('copy', $this->input->mode);
        self::assertNull($this->input->bitrate);
        self::assertSame(3, $this->input->streamIndex);
        self::assertSame('mp4', $this->input->outputContainer);
        self::assertSame('audio/mp4', $rendition->mimeType());
        self::assertTrue($rendition->isComplete());
    }

    public function testCopyNeverExposesIncompleteMp4Bytes(): void
    {
        $this->runtime->method('find')->willReturnCallback(function (): AudioRenditionRuntimeSnapshot {
            $this->write('incomplete', true);
            return $this->snapshot('running');
        });
        $this->expectException(AudioRenditionFailedException::class);
        $this->cache()->openCopy('track', $this->source, hash_file('xxh3', $this->source), 0);
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

    #[\PHPUnit\Framework\Attributes\DataProvider('copyAndEncodeStarts')]
    public function testQueuedWorkThatNoSlotClaimsReportsBusySlotsWhenTheStartWaitExpires(bool $copy): void
    {
        $this->runtime->method('find')->willReturnCallback(fn (): AudioRenditionRuntimeSnapshot => $this->snapshot('pending', live: false));
        $start = microtime(true);
        try {
            $copy
                ? $this->cache()->openCopy('track', $this->source, hash_file('xxh3', $this->source), 0)
                : $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
            self::fail('Work that no audio slot claims must report busy slots.');
        } catch (AudioRenditionBusyException $error) {
            self::assertStringContainsString('Every audio slot stayed busy', $error->getMessage());
            self::assertLessThan(.5, microtime(true) - $start);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function copyAndEncodeStarts(): iterable
    {
        yield 'encode' => [false];
        yield 'stream copy' => [true];
    }

    public function testClaimedWorkThatNeverStartsFailsWithinTheStartWait(): void
    {
        $this->runtime->method('find')->willReturnCallback(fn (): AudioRenditionRuntimeSnapshot => $this->snapshot('pending'));
        $start = microtime(true);
        try {
            $this->cache()->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
            self::fail('Claimed work that never starts must fail.');
        } catch (AudioRenditionFailedException $error) {
            self::assertStringContainsString('did not start in time', $error->getMessage());
            self::assertLessThan(.5, microtime(true) - $start);
        }
    }

    public function testQueuedWorkThatGetsASlotWithinTheStartWaitStreamsNormally(): void
    {
        $polls = 0;
        $this->runtime->method('find')->willReturnCallback(function () use (&$polls): AudioRenditionRuntimeSnapshot {
            if (++$polls <= 3) {
                return $this->snapshot('pending', live: false);
            }
            if ($polls === 4) {
                $this->write('encoded', true);
                return $this->snapshot('running');
            }
            return $this->complete();
        });
        $rendition = $this->cache(startTimeoutSeconds: 5)->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertFalse($rendition->isComplete());
        self::assertSame('encoded', implode('', iterator_to_array($rendition->chunks(), false)));
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

    public function testARenditionThatFailedUnderAnotherAacImplementationIsEncodedAgain(): void
    {
        $format = AudioRenditionFormat::Mp3;
        $signature = $format->sourceSignature($this->source, 192000, filemtime($this->source), filesize($this->source));
        $this->input = new AudioRenditionInput('track', $signature, $this->source, $format, 192000, $this->root . '/audio-renditions/track/' . $signature);
        $row = new class {
            public bool $failed = true;
        };
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('admit')->willReturnCallback(function (AudioRenditionInput $input): AudioRenditionRuntimeSnapshot {
            $this->input = $input;
            return $this->snapshot('pending');
        });
        $runtime->method('find')->willReturnCallback(function () use ($row): AudioRenditionRuntimeSnapshot {
            if ($row->failed) {
                return new AudioRenditionRuntimeSnapshot($this->input->key(), $this->input, 'failed', 1, null, null, 'AAC implementation "bAAC" is not available in this FFmpeg build', failedAacImplementation: AacImplementation::Baac);
            }
            $this->write('re-encoded');
            return $this->snapshot('complete');
        });
        $runtime->expects(self::once())->method('resetFailed')->with($this->input->key(), 1, AacImplementation::Ffmpeg)->willReturnCallback(static function () use ($row): bool {
            $row->failed = false;
            return true;
        });
        self::assertSame('re-encoded', file_get_contents($this->cache(1.0, 'ffmpeg', $runtime)->open('track', $this->source, AudioRenditionFormat::Mp3, 192000)->path));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failuresThatStayFailed')]
    public function testAFailureUnderTheSelectedOrNoAacImplementationStaysFailed(?AacImplementation $failedUnder): void
    {
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('admit')->willReturnCallback(function (AudioRenditionInput $input): AudioRenditionRuntimeSnapshot {
            $this->input = $input;
            return $this->snapshot('pending');
        });
        $runtime->method('find')->willReturnCallback(fn (): AudioRenditionRuntimeSnapshot => new AudioRenditionRuntimeSnapshot($this->input->key(), $this->input, 'failed', 1, null, null, 'invalid input', failedAacImplementation: $failedUnder));
        $runtime->expects(self::never())->method('resetFailed');
        $this->expectException(AudioRenditionFailedException::class);
        $this->expectExceptionMessage('invalid input');
        $this->cache(aacImplementation: 'baac', runtime: $runtime)->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
    }

    /** @return iterable<string, array{?AacImplementation}> */
    public static function failuresThatStayFailed(): iterable
    {
        yield 'failed under the selected implementation' => [AacImplementation::Baac];
        yield 'failed without an AAC implementation' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('evictionResetResults')]
    public function testEvictedCompletedRenditionIsRebuiltWithoutChangingTheSource(bool $resetWon): void
    {
        $generation = 1;
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('admit')->willReturnCallback(function (AudioRenditionInput $input): AudioRenditionRuntimeSnapshot {
            $this->input = $input;
            return $this->snapshot('complete');
        });
        $runtime->method('find')->willReturnCallback(function () use (&$generation): AudioRenditionRuntimeSnapshot {
            return $this->snapshot('complete', $generation);
        });
        $runtime->expects(self::once())->method('resetCompleted')->willReturnCallback(function (string $key, int $observedGeneration) use (&$generation, $resetWon): bool {
            self::assertSame($this->input->key(), $key);
            self::assertSame(1, $observedGeneration);
            $generation = 2;
            mkdir(dirname($this->path(2)), 0700, true);
            file_put_contents($this->path(2), 'rebuilt');
            return $resetWon;
        });
        $this->runtime = $runtime;
        // Establish the canonical source identity without starting a local encoder.
        $modified = filemtime($this->source);
        $size = filesize($this->source);
        self::assertIsInt($modified);
        self::assertIsInt($size);
        $signature = AudioRenditionFormat::Mp3->sourceSignature($this->source, 192000, $modified, $size);
        $this->input = new AudioRenditionInput('track', $signature, $this->source, AudioRenditionFormat::Mp3, 192000, $this->root . '/audio-renditions/track/' . $signature);
        $this->write('original');
        $cache = $this->cache();
        $first = $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertSame('original', file_get_contents($first->path));
        (new \App\Transcode\Infrastructure\Storage\TranscodeFileStorage(new SegmentFileResolver($this->root)))->deleteDirectory($this->root . '/audio-renditions/track');
        $rebuilt = $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
        self::assertSame('rebuilt', file_get_contents($rebuilt->path));
        self::assertStringContainsString('/generation-2/', $rebuilt->path);
    }

    /** @return iterable<string, array{bool}> */
    public static function evictionResetResults(): iterable
    {
        yield 'this request resets the completed generation' => [true];
        yield 'another request already reset and rebuilt it' => [false];
    }

    public function testReadOnlyMissNeverAdmitsOrResetsWork(): void
    {
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->expects(self::never())->method('admit');
        $runtime->expects(self::never())->method('resetCompleted');
        $runtime->method('find')->willReturn(null);
        $this->runtime = $runtime;
        self::assertNull($this->cache()->findComplete('track', $this->source, AudioRenditionFormat::Mp3, 192000));
    }

    public function testReadOnlyLookupRequiresCompleteExistingGeneration(): void
    {
        $format = AudioRenditionFormat::Mp3;
        $signature = $format->sourceSignature($this->source, 192000, filemtime($this->source), filesize($this->source));
        $this->input = new AudioRenditionInput('track', $signature, $this->source, $format, 192000, $this->root . '/audio-renditions/track/' . $signature);
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->expects(self::never())->method('admit');
        $runtime->expects(self::never())->method('resetCompleted');
        $runtime->method('find')->willReturnCallback(fn (): AudioRenditionRuntimeSnapshot => $this->snapshot('complete'));
        $this->runtime = $runtime;
        self::assertNull($this->cache()->findComplete('track', $this->source, $format, 192000));
        $this->write('finished');
        $hit = $this->cache()->findComplete('track', $this->source, $format, 192000);
        self::assertNotNull($hit);
        self::assertSame('finished', file_get_contents($hit->path));
        unlink($this->source);
        self::assertNull($this->cache()->findComplete('track', $this->source, $format, 192000));
        file_put_contents($this->source, 'changed source');
    }

    public function testRemuxMissingArtifactIsTerminalWithoutAdmission(): void
    {
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->expects(self::never())->method('admit');
        $runtime->expects(self::never())->method('resetCompleted');
        $runtime->method('find')->willReturn(null);
        $cache = new AudioRenditionCache(new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2)), new SegmentFileResolver($this->root), $runtime, new NullLogger(), $this->settings());
        $this->expectException(\App\Transcode\Application\Exception\MediaEncodingProhibitedException::class);
        $cache->open('track', $this->source, AudioRenditionFormat::Mp3, 192000);
    }

    public function testRemuxServesCompletedArtifactWithoutAdmission(): void
    {
        $format = AudioRenditionFormat::Mp3;
        $signature = $format->sourceSignature($this->source, 192000, filemtime($this->source), filesize($this->source));
        $this->input = new AudioRenditionInput('track', $signature, $this->source, $format, 192000, $this->root . '/audio-renditions/track/' . $signature);
        $this->write('finished');
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->expects(self::never())->method('admit');
        $runtime->expects(self::never())->method('resetCompleted');
        $runtime->method('find')->willReturnCallback(fn (): AudioRenditionRuntimeSnapshot => $this->snapshot('complete'));
        $cache = new AudioRenditionCache(new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2)), new SegmentFileResolver($this->root), $runtime, new NullLogger(), $this->settings());
        self::assertSame('finished', file_get_contents($cache->open('track', $this->source, $format, 192000)->path));
    }

    public function testEmptyCompletedArtifactIsAReadOnlyMiss(): void
    {
        $format = AudioRenditionFormat::Mp3;
        $signature = $format->sourceSignature($this->source, 192000, filemtime($this->source), filesize($this->source));
        $this->input = new AudioRenditionInput('track', $signature, $this->source, $format, 192000, $this->root . '/audio-renditions/track/' . $signature);
        $this->runtime->method('find')->willReturnCallback(fn (): AudioRenditionRuntimeSnapshot => $this->snapshot('complete'));
        $this->write('');
        self::assertNull($this->cache()->findComplete('track', $this->source, $format, 192000));
    }

    private function cache(float $startTimeoutSeconds = .01, string $aacImplementation = 'baac', ?AudioRenditionRuntimePort $runtime = null): AudioRenditionCache
    {
        return new AudioRenditionCache(new MediaExecutionPolicy(new DeploymentProfile()), new SegmentFileResolver($this->root), $runtime ?? $this->runtime, new NullLogger(), $this->settings($aacImplementation), pollIntervalSeconds: .001, startTimeoutSeconds: $startTimeoutSeconds);
    }

    private function settings(string $aacImplementation = 'baac'): SystemSettingsPortInterface
    {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(static fn (string $key): string => $key === TranscodeSettingDefinitions::AAC_IMPLEMENTATION ? $aacImplementation : throw new \LogicException($key));
        return $settings;
    }

    /** The running generation renamed its partial output into place and completed. */
    private function complete(): AudioRenditionRuntimeSnapshot
    {
        if (is_file($this->path() . '.part')) {
            rename($this->path() . '.part', $this->path());
        }
        return $this->snapshot('complete');
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
