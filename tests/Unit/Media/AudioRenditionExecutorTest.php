<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Transcode\Application\DTO\AudioRenditionClaim;
use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\DTO\AudioRenditionRuntimeSnapshot;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\Port\AudioRenditionFormat;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Infrastructure\Transcode\AudioRenditionExecutor;
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
        $signature = 'mp3-192k-' . hash('xxh64', filemtime($source) . ':' . filesize($source)) . '.mp3';
        $input = new AudioRenditionInput('track', $signature, $source, AudioRenditionFormat::Mp3, 192000, $this->root . '/rendition');
        $this->claim = new AudioRenditionClaim(new AudioRenditionRuntimeSnapshot($input->key(), $input, 'pending', 1, null, null, null, true), new MediaWorkerIdentity('baander.app:worker', str_repeat('a', 32), 'media', 1), 'token');
        $this->encoder = $this->root . '/encoder';
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
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
        (new AudioRenditionExecutor($runtime, new NullLogger(), $this->encoder))->run($this->claim, static fn (): bool => false);
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
            (new AudioRenditionExecutor($runtime, new NullLogger(), $this->encoder))->run($this->claim, static fn (): bool => false);
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
        (new AudioRenditionExecutor($runtime, new NullLogger(), $this->encoder))->run($this->claim, static fn (): bool => false);
        self::assertFileDoesNotExist($this->final());
    }

    public function testChangedSourceFailsWithoutLaunchingEncoder(): void
    {
        file_put_contents($this->claim->runtime->input->sourcePath, 'changed source');
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->expects(self::once())->method('fail')->with($this->claim, self::stringContains('source changed'))->willReturn(true);
        $runtime->expects(self::never())->method('start');
        (new AudioRenditionExecutor($runtime, new NullLogger(), $this->encoder))->run($this->claim, static fn (): bool => false);
    }

    public function testStopKillsAndReapsTheDirectEncoderChild(): void
    {
        $this->encoder("trap '' TERM\n" . 'echo $$ > ' . escapeshellarg($this->root . '/pid') . "\nexec sleep 30");
        $runtime = $this->createMock(AudioRenditionRuntimePort::class);
        $runtime->method('start')->willReturn(true);
        $runtime->method('renew')->willReturn(true);
        $runtime->expects(self::once())->method('defer')->willReturn(true);
        $runtime->expects(self::never())->method('complete');
        $start = microtime(true);
        (new AudioRenditionExecutor($runtime, new NullLogger(), $this->encoder))->run($this->claim, fn (): bool => is_file($this->root . '/pid'));
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
        (new AudioRenditionExecutor($runtime, new NullLogger(), $this->encoder))->run($this->claim, static fn (): bool => false);
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
        (new AudioRenditionExecutor($runtime, new NullLogger(), $this->encoder))->run($this->claim, static fn (): bool => false);
        self::assertFileDoesNotExist($this->final());
    }

    private function final(): string
    {
        return $this->claim->runtime->generationDirectory() . '/' . $this->claim->runtime->input->sourceSignature;
    }

    private function encoder(string $body): void
    {
        file_put_contents($this->encoder, "#!/bin/sh\nfor out; do :; done\n" . $body . "\n");
        chmod($this->encoder, 0700);
    }
}
