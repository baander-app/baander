<?php

declare(strict_types=1);

namespace Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Infrastructure\Swoole\SegmentAvailabilityTable;
use PHPUnit\Framework\TestCase;

final class SegmentAvailabilityTableTest extends TestCase
{
    private SegmentAvailabilityTable $table;
    private Uuid $jobId;

    protected function setUp(): void
    {
        $this->table = new SegmentAvailabilityTable();
        $this->jobId = new Uuid();
    }

    public function testMarkReadyThenIsReadyReturnsPath(): void
    {
        $this->table->markReady($this->jobId, 'p720', 0, '/var/transcode/video/seg_0.m4s');

        $this->assertSame('/var/transcode/video/seg_0.m4s', $this->table->isReady($this->jobId, 'p720', 0));
    }

    public function testIsReadyOnUnmarkedSegmentReturnsNull(): void
    {
        $this->assertNull($this->table->isReady($this->jobId, 'p720', 0));
    }

    public function testClearJobRemovesAllRowsForJob(): void
    {
        $this->table->markReady($this->jobId, 'p720', 0, '/path/seg_0.m4s');
        $this->table->markReady($this->jobId, 'p720', 1, '/path/seg_1.m4s');
        $this->table->markReady($this->jobId, 'en', 0, '/path/audio_0.m4s');

        $this->table->clearJob($this->jobId);

        $this->assertNull($this->table->isReady($this->jobId, 'p720', 0));
        $this->assertNull($this->table->isReady($this->jobId, 'p720', 1));
        $this->assertNull($this->table->isReady($this->jobId, 'en', 0));
    }

    public function testClearJobOnJobWithNoRowsIsSafeNoOp(): void
    {
        $emptyJobId = new Uuid();

        // Should not throw
        $this->table->clearJob($emptyJobId);

        $this->assertNull($this->table->isReady($emptyJobId, 'p720', 0));
    }

    public function testConcurrentMarkReadyForDifferentKeysDoesNotCollide(): void
    {
        $this->table->markReady($this->jobId, 'p720', 0, '/path/seg_0.m4s');
        $this->table->markReady($this->jobId, 'p720', 1, '/path/seg_1.m4s');
        $this->table->markReady($this->jobId, 'en', 0, '/path/audio_en_0.m4s');

        $this->assertSame('/path/seg_0.m4s', $this->table->isReady($this->jobId, 'p720', 0));
        $this->assertSame('/path/seg_1.m4s', $this->table->isReady($this->jobId, 'p720', 1));
        $this->assertSame('/path/audio_en_0.m4s', $this->table->isReady($this->jobId, 'en', 0));
    }

    public function testOverwritingReadyRowWithNewPathReturnsNewPath(): void
    {
        $this->table->markReady($this->jobId, 'p720', 5, '/old/path/seg_5.m4s');

        // Re-encode after a seek writes a new path for the same segment
        $this->table->markReady($this->jobId, 'p720', 5, '/new/path/seg_5.m4s');

        $this->assertSame('/new/path/seg_5.m4s', $this->table->isReady($this->jobId, 'p720', 5));
    }

    public function testClearJobDoesNotAffectOtherJobs(): void
    {
        $jobA = new Uuid();
        $jobB = new Uuid();

        $this->table->markReady($jobA, 'p720', 0, '/a/seg_0.m4s');
        $this->table->markReady($jobB, 'p720', 0, '/b/seg_0.m4s');

        $this->table->clearJob($jobA);

        $this->assertNull($this->table->isReady($jobA, 'p720', 0));
        $this->assertSame('/b/seg_0.m4s', $this->table->isReady($jobB, 'p720', 0));
    }
}
