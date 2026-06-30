<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Domain\ValueObject\SessionState;
use PHPUnit\Framework\TestCase;

class TranscodeSessionTest extends TestCase
{
    private Uuid $userId;
    private Uuid $jobId;
    private Uuid $videoId;
    private AudioProfile $audioProfile;

    protected function setUp(): void
    {
        $this->userId = new Uuid();
        $this->jobId = new Uuid();
        $this->videoId = new Uuid();
        $this->audioProfile = AudioProfile::streamingStereo();
    }

    public function testCreateGeneratesIdAndPublicId(): void
    {
        $session = TranscodeSession::create(
            $this->userId,
            $this->jobId,
            $this->videoId,
            $this->audioProfile,
        );

        $this->assertNotEmpty($session->getId()->toString());
        $this->assertNotEmpty($session->getPublicId()->toString());
        $this->assertSame(SessionState::Pending, $session->getSessionState());
        $this->assertSame(SessionPriority::Normal, $session->getPriority());
    }

    public function testStateTransitions(): void
    {
        $session = TranscodeSession::create($this->userId, $this->jobId, $this->videoId, $this->audioProfile);

        $session->markPreparing();
        $this->assertSame(SessionState::Preparing, $session->getSessionState());

        $session->markActive();
        $this->assertSame(SessionState::Active, $session->getSessionState());

        $session->markPaused();
        $this->assertSame(SessionState::Paused, $session->getSessionState());

        $session->markResumed();
        $this->assertSame(SessionState::Active, $session->getSessionState());

        $session->markCompleted();
        $this->assertSame(SessionState::Completed, $session->getSessionState());
    }

    public function testInvalidTransitionThrows(): void
    {
        $session = TranscodeSession::create($this->userId, $this->jobId, $this->videoId, $this->audioProfile);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot transition session from "pending" to "active"');

        $session->markActive();
    }

    public function testCancelFromPending(): void
    {
        $session = TranscodeSession::create($this->userId, $this->jobId, $this->videoId, $this->audioProfile);
        $session->markCancelled();

        $this->assertSame(SessionState::Cancelled, $session->getSessionState());
    }

    public function testCancelFromActive(): void
    {
        $session = TranscodeSession::create($this->userId, $this->jobId, $this->videoId, $this->audioProfile);
        $session->markPreparing();
        $session->markActive();
        $session->markCancelled();

        $this->assertSame(SessionState::Cancelled, $session->getSessionState());
    }

    public function testFailFromCompletedIsIdempotent(): void
    {
        $session = TranscodeSession::create($this->userId, $this->jobId, $this->videoId, $this->audioProfile);
        $session->markPreparing();
        $session->markActive();
        $session->markCompleted();
        $session->markFailed();

        $this->assertSame(SessionState::Completed, $session->getSessionState());
    }

    public function testUpdateCurrentSegment(): void
    {
        $session = TranscodeSession::create($this->userId, $this->jobId, $this->videoId, $this->audioProfile);
        $session->updateCurrentSegment(5);

        $this->assertSame(5, $session->getCurrentSegmentIndex());
    }

    public function testUpdateCurrentSegmentNegativeThrows(): void
    {
        $session = TranscodeSession::create($this->userId, $this->jobId, $this->videoId, $this->audioProfile);

        $this->expectException(\InvalidArgumentException::class);
        $session->updateCurrentSegment(-1);
    }

    public function testUpdateMetricsMerges(): void
    {
        $session = TranscodeSession::create($this->userId, $this->jobId, $this->videoId, $this->audioProfile);
        $session->updateMetrics(['bytesTransferred' => 1000]);
        $session->updateMetrics(['segmentsEncoded' => 5]);

        $this->assertSame(1000, $session->getMetrics()['bytesTransferred']);
        $this->assertSame(5, $session->getMetrics()['segmentsEncoded']);
    }

    public function testCreateWithHighPriority(): void
    {
        $session = TranscodeSession::create(
            $this->userId,
            $this->jobId,
            $this->videoId,
            $this->audioProfile,
            SessionPriority::High,
        );

        $this->assertSame(SessionPriority::High, $session->getPriority());
    }
}
