<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\SegmentMetadata;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Domain\ValueObject\SessionState;
use App\Transcode\Domain\ValueObject\TranscodeStatus;
use PHPUnit\Framework\TestCase;

class TranscodeJobTest extends TestCase
{
    private Uuid $videoId;

    protected function setUp(): void
    {
        $this->videoId = new Uuid();
    }

    public function testCreateGeneratesIdAndPublicId(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');

        $this->assertNotEmpty($job->getId()->toString());
        $this->assertNotEmpty($job->getPublicId()->toString());
        $this->assertSame(TranscodeStatus::Pending, $job->getStatus());
        $this->assertSame(0, $job->getReferenceCount());
        $this->assertSame(0, $job->getCompletedSegments());
    }

    public function testAttachSessionIncrementsReferenceCount(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');

        $job->attachSession();
        $this->assertSame(1, $job->getReferenceCount());

        $job->attachSession();
        $this->assertSame(2, $job->getReferenceCount());
    }

    public function testDetachSessionDecrementsAndReturnsTrueAtZero(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');
        $job->attachSession();

        $result = $job->detachSession();
        $this->assertTrue($result);
        $this->assertSame(0, $job->getReferenceCount());
    }

    public function testDetachSessionAtZeroDoesNotGoNegative(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');

        $result = $job->detachSession();
        $this->assertFalse($result);
        $this->assertSame(0, $job->getReferenceCount());
    }

    public function testMarkSegmentCompleted(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');
        $job->setTotalSegments(10);

        $job->markSegmentCompleted(0, '/seg/seg_000000.m4s', 500_000, 2.0);
        $this->assertSame(1, $job->getCompletedSegments());
        $this->assertSame(10.0, $job->getProgress());

        $job->markSegmentCompleted(1, '/seg/seg_000001.m4s', 450_000, 2.0);
        $this->assertSame(2, $job->getCompletedSegments());
        $this->assertSame(20.0, $job->getProgress());
    }

    public function testGetProgressReturnsZeroForNewJob(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');
        $this->assertSame(0.0, $job->getProgress());
    }

    public function testGetProgressReturnsHundredWhenComplete(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');
        $job->setTotalSegments(5);

        for ($i = 0; $i < 5; $i++) {
            $job->markSegmentCompleted($i, "/seg/seg_{$i}.m4s", 100, 2.0);
        }

        $this->assertSame(100.0, $job->getProgress());
    }

    public function testMarkInProgressFromPending(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');
        $job->markInProgress();

        $this->assertSame(TranscodeStatus::InProgress, $job->getStatus());
    }

    public function testMarkCompletedFromInProgress(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');
        $job->markInProgress();
        $job->markCompleted();

        $this->assertSame(TranscodeStatus::Completed, $job->getStatus());
    }

    public function testMarkFailedWhenAlreadyCompletedIsIdempotent(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');
        $job->markInProgress();
        $job->markCompleted();
        $job->markFailed('test');

        $this->assertSame(TranscodeStatus::Completed, $job->getStatus());
    }

    public function testMarkFailedFromInProgress(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');
        $job->markInProgress();
        $job->markFailed('encoder error');

        $this->assertSame(TranscodeStatus::Failed, $job->getStatus());
    }

    public function testMarkCancelled(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');
        $job->markCancelled();

        $this->assertSame(TranscodeStatus::Cancelled, $job->getStatus());
    }

    public function testUpdateProbeData(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');
        $probe = ['duration' => 100.0, 'width' => 1920];

        $job->updateProbeData($probe);
        $this->assertSame($probe, $job->getProbeData());
    }

    public function testCreateWithEmptyOutputDirectoryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TranscodeJob::create($this->videoId, QualityTier::p1080(), '');
    }

    public function testMarkAudioSegmentCompletedStoresEntry(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');

        $job->markAudioSegmentCompleted('en', 0, '/audio/en/seg_0.m4s', 50_000, 5.98);

        $map = $job->getAudioSegmentMap();
        $this->assertArrayHasKey('en:0', $map);
        $this->assertSame('/audio/en/seg_0.m4s', $map['en:0']['path']);
        $this->assertSame(50_000, $map['en:0']['size']);
        $this->assertSame(5.98, $map['en:0']['duration']);
    }

    public function testMarkAudioSegmentCompletedKeysByLanguageAndIndex(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');

        $job->markAudioSegmentCompleted('en', 0, '/audio/en/seg_0.m4s', 50_000, 6.0);
        $job->markAudioSegmentCompleted('en', 1, '/audio/en/seg_1.m4s', 48_000, 6.0);
        $job->markAudioSegmentCompleted('fr', 0, '/audio/fr/seg_0.m4s', 49_000, 6.0);

        $map = $job->getAudioSegmentMap();
        $this->assertCount(3, $map);
        $this->assertArrayHasKey('en:0', $map);
        $this->assertArrayHasKey('en:1', $map);
        $this->assertArrayHasKey('fr:0', $map);
    }

    public function testMarkAudioSegmentCompletedOverwritesDuplicateKey(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');

        $job->markAudioSegmentCompleted('en', 0, '/old.m4s', 10, 5.0);
        $job->markAudioSegmentCompleted('en', 0, '/new.m4s', 20, 6.0);

        $map = $job->getAudioSegmentMap();
        $this->assertCount(1, $map);
        $this->assertSame('/new.m4s', $map['en:0']['path']);
    }

    public function testGetAudioSegmentMapReturnsEmptyArrayForNewJob(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');

        $this->assertSame([], $job->getAudioSegmentMap());
    }

    public function testSetAudioTrackLanguages(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');

        $job->setAudioTrackLanguages(['en', 'fr', 'es']);

        $this->assertSame(['en', 'fr', 'es'], $job->getAudioTrackLanguages());
    }

    public function testSetMeasuredLoudness(): void
    {
        $job = TranscodeJob::create($this->videoId, QualityTier::p1080(), '/storage/conv');

        $loudness = ['input_i' => -18.5, 'input_tp' => -1.2];
        $job->setMeasuredLoudness($loudness);

        $this->assertSame($loudness, $job->getMeasuredLoudness());
    }
}
