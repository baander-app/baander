<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\FFmpegPortInterface;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\EncoderProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\FFmpeg\SegmentEncoder;
use App\Transcode\Infrastructure\Swoole\ProcessSpawnerInterface;
use App\Transcode\Infrastructure\Swoole\TranscodeStreamManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TranscodeStreamManagerOwnershipTest extends TestCase
{
    private string $directory;
    private TranscodeStreamManager $manager;
    private TranscodeJob $job;
    private ?\Closure $onRunning = null;
    private ?\Closure $onClose = null;
    private ?\Closure $onReady = null;
    private ?\Closure $onExit = null;
    private int $spawned = 0;
    private bool $running = true;
    /** @var list<mixed> */
    private array $closed = [];
    /** @var list<int> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-stream-owner-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('resolveJobDirectory')->willReturn($this->directory);
        $encoder = new SegmentEncoder($this->createStub(FFmpegPortInterface::class), $storage, EncoderProfile::software('libx265'));
        $spawner = $this->createStub(ProcessSpawnerInterface::class);
        $spawner->method('spawn')->willReturnCallback(function (): array {
            // Deliberately reuse the process resource to prove stream identity
            // does not depend on a reusable resource handle or PID.
            ++$this->spawned;
            return ['resource' => 'reused-handle', 'pipes' => [], 'pid' => 100];
        });
        $spawner->method('isRunning')->willReturnCallback(function (): bool {
            $callback = $this->onRunning;
            $this->onRunning = null;
            $callback?->__invoke();
            return $this->running;
        });
        $spawner->method('close')->willReturnCallback(function (mixed $resource): void {
            $this->closed[] = $resource;
            $callback = $this->onClose;
            $this->onClose = null;
            $callback?->__invoke();
        });
        $spawner->method('exitCode')->willReturnCallback(function (): int {
            $callback = $this->onExit;
            $this->onExit = null;
            $callback?->__invoke();
            return -1;
        });
        $availability = $this->createStub(SegmentAvailabilityInterface::class);
        $availability->method('markReady')->willReturnCallback(function (Uuid $jobId, string $tier, int $index): void {
            $this->published[] = $index;
            $callback = $this->onReady;
            $this->onReady = null;
            $callback?->__invoke();
        });
        $this->manager = new TranscodeStreamManager($availability, $storage, $encoder, new NullLogger(), $spawner);
        $this->job = TranscodeJob::create(Uuid::generate(), QualityTier::p720(), $this->directory);
        $this->start();
    }

    protected function tearDown(): void
    {
        $this->onRunning = $this->onClose = $this->onReady = $this->onExit = null;
        $this->manager->stopAll();
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testStopDuringRunningCheckDoesNotResurrectOrCloseTwice(): void
    {
        $this->onRunning = fn() => $this->manager->stopStream($this->job->getId());

        self::assertFalse($this->manager->pollOnce($this->job->getId()));
        self::assertSame(0, $this->manager->getActiveStreamCount());
        $this->manager->stopStream($this->job->getId());
        self::assertCount(1, $this->closed);
    }

    public function testReplacementDuringOldPollRetainsItsOwnEntry(): void
    {
        $this->onRunning = function (): void {
            $this->manager->stopStream($this->job->getId());
            $this->start();
        };

        self::assertFalse($this->manager->pollOnce($this->job->getId()));
        self::assertSame(2, $this->spawned);
        self::assertSame(1, $this->manager->getActiveStreamCount());
        self::assertTrue($this->manager->pollOnce($this->job->getId()));
        self::assertCount(1, $this->closed);
    }

    public function testOldCloseCannotRemoveAReplacementStartedDuringClose(): void
    {
        $this->onClose = fn() => $this->start();

        $this->manager->stopStream($this->job->getId());

        self::assertSame(2, $this->spawned);
        self::assertSame(1, $this->manager->getActiveStreamCount());
        self::assertTrue($this->manager->pollOnce($this->job->getId()));
        self::assertCount(1, $this->closed);
    }

    public function testStopDuringPublicationPreventsFurtherOldPublications(): void
    {
        $this->writeFragments();
        $this->onReady = fn() => $this->manager->stopStream($this->job->getId());

        self::assertFalse($this->manager->pollOnce($this->job->getId()));
        self::assertSame([0], $this->published);
        self::assertSame(0, $this->manager->getActiveStreamCount());
        self::assertCount(1, $this->closed);
    }

    public function testReplacementDuringPublicationKeepsItsOwnDedupeState(): void
    {
        $this->writeFragments();
        $this->onReady = function (): void {
            $this->manager->stopStream($this->job->getId());
            $this->start();
        };

        self::assertFalse($this->manager->pollOnce($this->job->getId()));
        self::assertSame([0], $this->published);
        self::assertTrue($this->manager->pollOnce($this->job->getId()));
        self::assertSame([0, 0, 1], $this->published);
        self::assertCount(1, $this->closed);
    }

    public function testStopDuringExitCheckDoesNotReportOldProcessFailure(): void
    {
        $this->writeFragments();
        $this->running = false;
        $this->onExit = fn() => $this->manager->stopStream($this->job->getId());

        self::assertFalse($this->manager->pollOnce($this->job->getId()));
        self::assertSame(0, $this->manager->getActiveStreamCount());
        self::assertCount(1, $this->closed);
    }

    private function start(): void
    {
        $this->manager->startStream($this->job, '/source.mkv', QualityTier::p720(), '');
    }

    private function writeFragments(): void
    {
        file_put_contents($this->directory . '/v0_a0_720p_0.m4s', 'first');
        file_put_contents($this->directory . '/v0_a0_720p_1.m4s', 'second');
    }
}
