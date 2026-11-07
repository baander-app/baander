<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Transcode\Application\DTO\AudioRenditionClaim;
use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Tests\Support\StubAacImplementationProbe;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Application\DTO\AudioRenditionRuntimeSnapshot;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Domain\ValueObject\AacImplementation;
use App\Transcode\Infrastructure\Transcode\AudioRenditionExecutor;
use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AudioRenditionExecutorTest extends TestCase
{
    private string $root;
    private string $encoder;
    private AudioRenditionClaim $claim;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/audio-executor-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $source = $this->root . '/source.flac';
        file_put_contents($source, 'source');
        $signature = AudioRenditionFormat::Mp3->sourceSignature($source, 192000, (int) filemtime($source), (int) filesize($source));
        $input = new AudioRenditionInput('track', $signature, $source, AudioRenditionFormat::Mp3, 192000, $this->root . '/rendition');
        $this->claim = new AudioRenditionClaim(new AudioRenditionRuntimeSnapshot($input->key(), $input, 'pending', 1, null, null, null, true), new MediaWorkerIdentity('baander.app:worker', str_repeat('a', 32), 'media', 1), 'token');
        $this->encoder = $this->root . '/encoder';
    }

    protected function tearDown(): void
    {
        ProcessExecutor::configureNativeExecution(null);
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testRemuxRejectsRecoveredEncodeWithoutStartingOrDeferringIt(): void
    {
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->expects(self::never())->method('start');
        $runtime->expects(self::never())->method('defer');
        $runtime->expects(self::never())->method('complete');
        $runtime->expects(self::once())->method('fail')->with($this->claim, 'Audio and video encoding is unavailable in a remux deployment.')->willReturn(true);
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::never())->method('submit');
        ProcessExecutor::configureNativeExecution($client);
        $policy = new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2));
        (new AudioRenditionExecutor($policy, $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), $this->encoder))->run($this->claim, static fn (): bool => false);
        self::assertDirectoryDoesNotExist($this->claim->runtime->generationDirectory());
    }

    public function testMissingNativeClientFailsWithoutLocalExecution(): void
    {
        $this->encoder('touch ' . escapeshellarg($this->root . '/ran'));
        ProcessExecutor::configureNativeExecution(null);
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->expects(self::once())->method('fail')->with($this->claim, 'Native execution is unavailable.')->willReturn(true);
        $runtime->expects(self::never())->method('complete');
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), $this->encoder))->run($this->claim, static fn (): bool => false);
        self::assertFileDoesNotExist($this->root . '/ran');
    }

    public function testRemoteEncodeRenewsAndPublishesThroughTheOwnershipFence(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $handle = new NativeExecutionHandle(str_repeat('a', 64), 1);
        $partial = $this->final() . '.part';
        $commands = [];
        $client->expects(self::exactly(2))->method('submit')->willReturnCallback(function (array $argv, float $timeout) use ($partial, $handle, &$commands): NativeExecutionHandle {
            $commands[] = $argv;
            if (count($commands) === 1) {
                self::assertSame('/usr/bin/ffprobe', $argv[0]);
                self::assertSame(60.0, $timeout);
            } else {
                self::assertSame('/usr/bin/ffmpeg', $argv[0]);
                self::assertSame(3600.0, $timeout);
                self::assertSame($partial, end($argv));
                self::assertContains($this->claim->runtime->input->sourcePath, $argv);
            }
            return $handle;
        });
        $client->method('inspect')->willReturnCallback(function () use ($partial, &$commands): array {
            if (count($commands) === 1) {
                return ['state' => 'exited', 'exitCode' => 0, 'stdout' => '{"streams":[{"codec_name":"flac"}]}', 'stderr' => ''];
            }
            file_put_contents($partial, 'remote audio');
            return ['state' => 'exited', 'exitCode' => 0, 'stdout' => '', 'stderr' => ''];
        });
        $client->expects(self::exactly(2))->method('release')->willReturn(true);
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->expects(self::exactly(2))->method('renew')->willReturn(true);
        $runtime->method('withOwnership')->willReturnCallback(function (AudioRenditionClaim $claim, callable $publish): bool {
            self::assertFileDoesNotExist($this->final());
            $publish();
            return true;
        });
        $runtime->expects(self::once())->method('complete')->willReturn(true);
        $runtime->expects(self::never())->method('fail');
        ProcessExecutor::configureNativeExecution($client);
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), '/usr/bin/ffmpeg'))->run($this->claim, static fn (): bool => false);
        self::assertSame('remote audio', file_get_contents($this->final()));
    }

    public function testRemuxCopiesVerifiedSelectedAacIntoCompletedMp4(): void
    {
        $source = $this->claim->runtime->input->sourcePath;
        $sourceHash = hash_file('xxh3', $source);
        $signature = AudioRenditionInput::copySourceSignature($source, $sourceHash, 3, filemtime($source), filesize($source));
        $input = new AudioRenditionInput('track', $signature, $source, AudioRenditionFormat::Aac, null,
            $this->root . '/rendition', 'copy', 3, 'mp4', $sourceHash);
        $this->claim = new AudioRenditionClaim(new AudioRenditionRuntimeSnapshot($input->key(), $input, 'pending', 1, null, null, null, true), $this->claim->worker, 'token');
        $command = [];
        $commands = [];
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $client->method('submit')->willReturnCallback(static function (array $argv) use (&$command, &$commands): NativeExecutionHandle {
            $commands[] = $command = $argv;
            return new NativeExecutionHandle(str_repeat('a', 64), count($commands));
        });
        $client->method('inspect')->willReturnCallback(function () use (&$command): array {
            $output = '';
            if ($command[0] === '/usr/bin/ffprobe') {
                $output = '{"streams":[{"index":3,"codec_type":"audio","codec_name":"aac"}]}';
            } else {
                file_put_contents(end($command), 'copied AAC');
            }
            return ['state' => 'exited', 'exitCode' => 0, 'stdout' => $output, 'stderr' => ''];
        });
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->method('withOwnership')->willReturnCallback(static function (AudioRenditionClaim $claim, callable $publish): bool { $publish(); return true; });
        $runtime->expects(self::once())->method('complete')->willReturn(true);
        $runtime->expects(self::never())->method('fail');
        $policy = new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2));
        (new AudioRenditionExecutor($policy, $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), '/usr/bin/ffmpeg'))->run($this->claim, static fn (): bool => false);
        self::assertCount(2, $commands);
        self::assertContains('0:3', $commands[1]);
        self::assertContains('copy', $commands[1]);
        self::assertNotContains('-b:a', $commands[1]);
        self::assertSame('copied AAC', file_get_contents($this->final()));
    }

    public function testChangedCopySourceFailsBeforeAnyNativeAdmission(): void
    {
        $this->selectCopy();
        $source = $this->claim->runtime->input->sourcePath;
        $modified = filemtime($source);
        file_put_contents($source, 'mutate');
        touch($source, $modified);
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::never())->method('submit');
        ProcessExecutor::configureNativeExecution($client);
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->expects(self::once())->method('fail')->with($this->claim, 'The admitted audio source changed before copying.')->willReturn(true);
        $runtime->expects(self::never())->method('complete');
        $policy = new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2));
        (new AudioRenditionExecutor($policy, $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), '/usr/bin/ffmpeg'))->run($this->claim, static fn (): bool => false);
        self::assertFileDoesNotExist($this->final());
    }

    public function testNonAacSourceCannotStartAnAudioCopy(): void
    {
        $this->selectCopy();
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::once())->method('submit')->with(self::callback(static fn (array $arguments): bool => $arguments[0] === '/usr/bin/ffprobe'), 60)
            ->willReturn(new NativeExecutionHandle(str_repeat('a', 64), 1));
        $client->method('inspect')->willReturn(['state' => 'exited', 'exitCode' => 0,
            'stdout' => '{"streams":[{"index":3,"codec_type":"audio","codec_name":"mp3"}]}', 'stderr' => '']);
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->expects(self::once())->method('fail')->with($this->claim, 'The selected source stream is not AAC audio.')->willReturn(true);
        $runtime->expects(self::never())->method('complete');
        $policy = new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2));
        (new AudioRenditionExecutor($policy, $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), '/usr/bin/ffmpeg'))->run($this->claim, static fn (): bool => false);
        self::assertFileDoesNotExist($this->final());
    }

    private function selectCopy(): void
    {
        $source = $this->claim->runtime->input->sourcePath;
        $hash = hash_file('xxh3', $source);
        $signature = AudioRenditionInput::copySourceSignature($source, $hash, 3, filemtime($source), filesize($source));
        $input = new AudioRenditionInput('track', $signature, $source, AudioRenditionFormat::Aac, null, $this->root . '/rendition', 'copy', 3, 'mp4', $hash);
        $this->claim = new AudioRenditionClaim(new AudioRenditionRuntimeSnapshot($input->key(), $input, 'pending', 1, null, null, null, true), $this->claim->worker, 'token');
    }

    public function testRemoteOwnershipLossStopsTheAttemptWithoutPublishing(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $handle = new NativeExecutionHandle(str_repeat('a', 64), 1);
        $client->method('submit')->willReturn($handle);
        $client->expects(self::once())->method('signal')->with($handle, 'stop')->willReturn(['state' => 'running']);
        $client->method('inspect')->willReturn(['state' => 'exited']);
        $client->expects(self::once())->method('release')->willReturn(true);
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(false);
        $runtime->expects(self::once())->method('fail')->with($this->claim, 'Audio rendition worker lost ownership.')->willReturn(true);
        $runtime->expects(self::never())->method('complete');
        ProcessExecutor::configureNativeExecution($client);
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), '/usr/bin/ffmpeg'))->run($this->claim, static fn (): bool => false);
        self::assertFileDoesNotExist($this->final());
    }

    public function testUncertainSubmissionEscapesToTheWorkerSupervisor(): void
    {
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $client->method('submit')->willThrowException(new NativeExecutionException('Native admission is uncertain.'));
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->expects(self::never())->method('fail');
        $runtime->expects(self::never())->method('complete');
        ProcessExecutor::configureNativeExecution($client);
        $this->expectException(NativeExecutionException::class);
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), '/usr/bin/ffmpeg'))->run($this->claim, static fn (): bool => false);
    }

    public function testSuccessfulEncodePublishesOnlyInsideTheOwnershipFence(): void
    {
        $this->encoder("printf 'encoded' > \"\$out\"");
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('renew')->willReturn(true);
        $runtime->method('start')->willReturn(true);
        $runtime->method('withOwnership')->willReturnCallback(function (AudioRenditionClaim $claim, callable $publish): bool {
            self::assertFileDoesNotExist($this->final());
            $publish();
            return true;
        });
        $runtime->expects(self::once())->method('complete')->willReturnCallback(function (): bool {
            self::assertSame('encoded', file_get_contents($this->final()));
            return true;
        });
        $runtime->expects(self::never())->method('fail');
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), $this->encoder))->run($this->claim, static fn (): bool => false);
        self::assertFileDoesNotExist($this->final() . '.part');
    }

    public function testBusyCanonicalLockDefersWithoutStartingAnEncoder(): void
    {
        $this->encoder("touch " . escapeshellarg($this->root . '/ran'));
        $lock = fopen($this->claim->runtime->input->outputDirectory . '.lock', 'c');
        self::assertIsResource($lock);
        flock($lock, LOCK_EX);
        try {
            $runtime = $this->createMock(AudioRenditionRuntimePort::class);
            $runtime->expects(self::once())->method('defer')->willReturn(true);
            $runtime->expects(self::never())->method('start');
            (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), $this->encoder))->run($this->claim, static fn (): bool => false);
            self::assertFileDoesNotExist($this->root . '/ran');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testStaleFenceCannotPublishFinalOutput(): void
    {
        $this->encoder("printf 'encoded' > \"\$out\"");
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->method('withOwnership')->willReturn(false);
        $runtime->expects(self::never())->method('complete');
        $runtime->method('fail')->willReturn(false);
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), $this->encoder))->run($this->claim, static fn (): bool => false);
        self::assertFileDoesNotExist($this->final());
    }

    public function testChangedSourceFailsWithoutLaunchingEncoder(): void
    {
        file_put_contents($this->claim->runtime->input->sourcePath, 'changed source');
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->expects(self::once())->method('fail')->with($this->claim, self::stringContains('source changed'))->willReturn(true);
        $runtime->expects(self::never())->method('start');
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), $this->encoder))->run($this->claim, static fn (): bool => false);
    }

    public function testStopKillsAndReapsTheRemoteEncoderAttempt(): void
    {
        $this->encoder("trap '' TERM\n" . 'echo $$ > ' . escapeshellarg($this->root . '/pid') . "\nexec sleep 30");
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->expects(self::once())->method('defer')->willReturn(true);
        $runtime->expects(self::never())->method('complete');
        $start = microtime(true);
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), $this->encoder))->run($this->claim, fn (): bool => is_file($this->root . '/pid'));
        self::assertLessThan(3, microtime(true) - $start);
        $pid = (int) file_get_contents($this->root . '/pid');
        self::assertDirectoryDoesNotExist('/proc/' . $pid);
    }

    public function testEncoderFailureIsPublishedAndOnlyItsPartialFileIsRemoved(): void
    {
        $this->encoder("printf 'broken' > \"\$out\"\nprintf 'invalid input' >&2\nexit 7");
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->expects(self::once())->method('fail')->with($this->claim, self::stringContains('code 7: invalid input'))->willReturn(true);
        $runtime->expects(self::never())->method('complete');
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), $this->encoder))->run($this->claim, static fn (): bool => false);
        self::assertFileDoesNotExist($this->final());
        self::assertFileDoesNotExist($this->final() . '.part');
    }

    public function testAnEncoderThatExitsWithoutOutputCannotBecomeComplete(): void
    {
        $this->encoder('exit 0');
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->expects(self::once())->method('fail')->willReturn(true);
        $runtime->expects(self::never())->method('complete');
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), new StubAacImplementationProbe(), $this->encoder))->run($this->claim, static fn (): bool => false);
        self::assertFileDoesNotExist($this->final());
    }

    /** @return list<list<string>> the argument vectors of the spawned commands, in order */
    private function runEncode(string $sourceCodec, AudioRenditionFormat $format, string $implementation): array
    {
        $source = $this->claim->runtime->input->sourcePath;
        $signature = $format->sourceSignature($source, 128000, (int) filemtime($source), (int) filesize($source));
        $input = new AudioRenditionInput('track', $signature, $source, $format, 128000, $this->root . '/rendition');
        $this->claim = new AudioRenditionClaim(new AudioRenditionRuntimeSnapshot($input->key(), $input, 'pending', 1, null, null, null, true), $this->claim->worker, 'token');
        $command = [];
        $commands = [];
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $client->method('submit')->willReturnCallback(static function (array $argv) use (&$command, &$commands): NativeExecutionHandle {
            $commands[] = $command = $argv;
            return new NativeExecutionHandle(str_repeat('a', 64), count($commands));
        });
        $client->method('inspect')->willReturnCallback(function () use (&$command, $sourceCodec): array {
            $output = '';
            if ($command[0] === '/usr/bin/ffprobe') {
                $output = json_encode(['streams' => [['codec_name' => $sourceCodec]]]);
            } else {
                file_put_contents(end($command), 'encoded');
            }
            return ['state' => 'exited', 'exitCode' => 0, 'stdout' => $output, 'stderr' => ''];
        });
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->method('withOwnership')->willReturnCallback(static function (AudioRenditionClaim $claim, callable $publish): bool { $publish(); return true; });
        $runtime->expects(self::once())->method('complete')->willReturn(true);
        $runtime->expects(self::never())->method('fail');
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings($implementation), new StubAacImplementationProbe(), '/usr/bin/ffmpeg'))->run($this->claim, static fn (): bool => false);
        self::assertCount(2, $commands);
        self::assertSame('/usr/bin/ffprobe', $commands[0][0]);
        self::assertContains('a:0', $commands[0]);
        return $commands;
    }

    public function testAacRenditionsUseTheSelectedEncoderAndDecodeAacSourcesWithIt(): void
    {
        foreach (['baac' => 'libbaac', 'fdk_aac' => 'libfdk_aac', 'ffmpeg' => 'aac'] as $implementation => $codec) {
            $this->setUp();
            $commands = $this->runEncode('aac', AudioRenditionFormat::Aac, $implementation);
            $encode = $commands[1];
            self::assertSame($codec, $encode[array_search('-c:a:0', $encode, true) + 1], $implementation);
            self::assertLessThan(array_search('-i', $encode, true), array_search('-c:a:0', $encode, true));
            self::assertSame($codec, $encode[array_search('-c:a', $encode, true) + 1]);
            self::assertContains('adts', $encode);
            $this->tearDown();
        }
        $this->setUp();
    }

    public function testAnAacSourceToOpusIsDecodedBySelectedImplementation(): void
    {
        $encode = $this->runEncode('aac', AudioRenditionFormat::Opus, 'baac')[1];
        self::assertSame('libbaac', $encode[array_search('-c:a:0', $encode, true) + 1]);
        self::assertSame('libopus', $encode[array_search('-c:a', $encode, true) + 1]);
    }

    public function testANonAacSourceGetsNoForcedDecoderAndNoAacCodecName(): void
    {
        $encode = $this->runEncode('flac', AudioRenditionFormat::Mp3, 'baac')[1];
        self::assertNotContains('-c:a:0', $encode);
        foreach (['libbaac', 'libfdk_aac', 'aac'] as $token) {
            self::assertNotContains($token, $encode);
        }
        self::assertSame('libmp3lame', $encode[array_search('-c:a', $encode, true) + 1]);
    }

    public function testAMissingSelectedImplementationFailsBeforeAnyEncoderStartsButLeavesCopiesAlone(): void
    {
        $source = $this->claim->runtime->input->sourcePath;
        $signature = AudioRenditionFormat::Aac->sourceSignature($source, 128000, (int) filemtime($source), (int) filesize($source));
        $input = new AudioRenditionInput('track', $signature, $source, AudioRenditionFormat::Aac, 128000, $this->root . '/rendition');
        $this->claim = new AudioRenditionClaim(new AudioRenditionRuntimeSnapshot($input->key(), $input, 'pending', 1, null, null, null, true), $this->claim->worker, 'token');
        $commands = [];
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $client->method('submit')->willReturnCallback(static function (array $argv) use (&$commands): NativeExecutionHandle {
            $commands[] = $argv;
            return new NativeExecutionHandle(str_repeat('a', 64), count($commands));
        });
        $client->method('inspect')->willReturn(['state' => 'exited', 'exitCode' => 0, 'stdout' => '{"streams":[{"codec_name":"flac"}]}', 'stderr' => '']);
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->expects(self::once())->method('fail')->with($this->claim, 'AAC implementation "bAAC" is not available in this FFmpeg build', AacImplementation::Baac)->willReturn(true);
        $runtime->expects(self::never())->method('complete');
        $probe = new StubAacImplementationProbe(['libfdk_aac', 'aac'], ['libfdk_aac', 'aac']);
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), $probe, '/usr/bin/ffmpeg'))->run($this->claim, static fn (): bool => false);
        self::assertCount(1, $commands, 'only the source probe ran');
        self::assertSame('/usr/bin/ffprobe', $commands[0][0]);
        self::assertFileDoesNotExist($this->final());

        $this->selectCopy();
        $commands = [];
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->method('withOwnership')->willReturnCallback(static function (AudioRenditionClaim $claim, callable $publish): bool { $publish(); return true; });
        $runtime->expects(self::once())->method('complete')->willReturn(true);
        $runtime->expects(self::never())->method('fail');
        $copyClient = $this->createStub(NativeExecutionClientInterface::class);
        $command = [];
        $copyClient->method('submit')->willReturnCallback(static function (array $argv) use (&$command, &$commands): NativeExecutionHandle {
            $commands[] = $command = $argv;
            return new NativeExecutionHandle(str_repeat('a', 64), count($commands));
        });
        $copyClient->method('inspect')->willReturnCallback(function () use (&$command): array {
            if ($command[0] === '/usr/bin/ffprobe') {
                return ['state' => 'exited', 'exitCode' => 0, 'stdout' => '{"streams":[{"index":3,"codec_type":"audio","codec_name":"aac"}]}', 'stderr' => ''];
            }
            file_put_contents(end($command), 'copied AAC');
            return ['state' => 'exited', 'exitCode' => 0, 'stdout' => '', 'stderr' => ''];
        });
        $copyClient->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($copyClient);
        (new AudioRenditionExecutor(new MediaExecutionPolicy(new DeploymentProfile()), $runtime, new NullLogger(), $this->settings(), $probe, '/usr/bin/ffmpeg'))->run($this->claim, static fn (): bool => false);
        self::assertSame('copied AAC', file_get_contents($this->final()));
    }

    private function settings(string $implementation = 'baac'): SystemSettingsPortInterface
    {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(static fn (string $key): string => $key === TranscodeSettingDefinitions::AAC_IMPLEMENTATION ? $implementation : throw new \LogicException($key));
        return $settings;
    }

    private function final(): string
    {
        return $this->claim->runtime->generationDirectory() . '/' . $this->claim->runtime->input->sourceSignature;
    }

    private function encoder(string $body): void
    {
        file_put_contents($this->encoder, "#!/bin/sh\nfor out; do :; done\n" . $body . "\n");
        chmod($this->encoder, 0700);
        $process = null;
        $pipes = [];
        $stderr = '';
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $client->method('submit')->willReturnCallback(static function (array $argv) use (&$process, &$pipes): NativeExecutionHandle {
            $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if ($process === false) {
                throw new \RuntimeException('Unable to start the test encoder.');
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            return new NativeExecutionHandle(str_repeat('b', 64), 1);
        });
        $client->method('inspect')->willReturnCallback(static function () use (&$process, &$pipes, &$stderr): array {
            $status = proc_get_status($process);
            stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            return ['state' => $status['running'] ? 'running' : 'exited', 'exitCode' => $status['exitcode'], 'stdout' => '', 'stderr' => $stderr];
        });
        $client->method('signal')->willReturnCallback(static function () use (&$process): array {
            proc_terminate($process, 9);
            return ['state' => 'running'];
        });
        $client->method('release')->willReturnCallback(static function () use (&$process, &$pipes): bool {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return true;
        });
        ProcessExecutor::configureNativeExecution($client);
    }
}
