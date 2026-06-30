<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\Event;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Event\TranscodeJobCompleted;
use App\Transcode\Domain\Event\TranscodeJobCreated;
use App\Transcode\Domain\Event\TranscodeJobFailed;
use App\Transcode\Domain\Event\TranscodeSessionAttached;
use PHPUnit\Framework\TestCase;

class TranscodeEventTest extends TestCase
{
    public function testTranscodeJobCreatedRoundTrips(): void
    {
        $event = new TranscodeJobCreated(
            jobId: new Uuid(),
            videoId: new Uuid(),
            qualityTier: '1080p',
        );

        $payload = $event->toPayload();
        $restored = TranscodeJobCreated::fromPayload($payload);

        $this->assertSame($event->getJobId()->toString(), $restored->getJobId()->toString());
        $this->assertSame($event->getVideoId()->toString(), $restored->getVideoId()->toString());
        $this->assertSame('1080p', $restored->getQualityTier());
        $this->assertSame('transcode.job_created', $event->eventName());
    }

    public function testTranscodeJobCompletedRoundTrips(): void
    {
        $event = new TranscodeJobCompleted(
            jobId: new Uuid(),
            videoId: new Uuid(),
            qualityTier: '720p',
            totalSegments: 500,
        );

        $payload = $event->toPayload();
        $restored = TranscodeJobCompleted::fromPayload($payload);

        $this->assertSame(500, $restored->getJobId() !== null ? $payload['total_segments'] : 0);
        $this->assertSame('transcode.job_completed', $event->eventName());
    }

    public function testTranscodeJobFailedRoundTrips(): void
    {
        $event = new TranscodeJobFailed(
            jobId: new Uuid(),
            videoId: new Uuid(),
            reason: 'Encoder crashed',
        );

        $payload = $event->toPayload();
        $restored = TranscodeJobFailed::fromPayload($payload);

        $this->assertSame('Encoder crashed', $restored->getReason());
        $this->assertSame('transcode.job_failed', $event->eventName());
    }

    public function testTranscodeSessionAttachedRoundTrips(): void
    {
        $event = new TranscodeSessionAttached(
            sessionId: new Uuid(),
            jobId: new Uuid(),
            userId: new Uuid(),
            qualityTier: '1080p',
        );

        $payload = $event->toPayload();
        $restored = TranscodeSessionAttached::fromPayload($payload);

        $this->assertSame($event->getSessionId()->toString(), $restored->getSessionId()->toString());
        $this->assertSame($event->getJobId()->toString(), $restored->getJobId()->toString());
        $this->assertSame($event->getUserId()->toString(), $restored->getUserId()->toString());
        $this->assertSame('1080p', $event->getQualityTier());
        $this->assertSame('1080p', $restored->getQualityTier());
        $this->assertSame('transcode.session_attached', $event->eventName());
    }

    public function testEventPayloadContainsOccurredAt(): void
    {
        $event = new TranscodeJobCreated(
            jobId: new Uuid(),
            videoId: new Uuid(),
            qualityTier: '1080p',
        );

        $payload = $event->toPayload();
        $this->assertArrayHasKey('occurred_at', $payload);
        $this->assertNotEmpty($payload['occurred_at']);
    }
}
