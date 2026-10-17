<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\TranscodePublicView;
use App\Transcode\Application\Port\TranscodeStreamingPortInterface;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class TranscodePublicViewTest extends TestCase
{
    public function testSessionRelationshipsAreProjectedAsIndependentPublicIdentities(): void
    {
        $session = TranscodeSession::create(new Uuid(), new Uuid(), new Uuid(), AudioProfile::streamingStereo());
        $user = new PublicId();
        $job = new PublicId();
        $video = new PublicId();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->with(self::callback(static fn (string $sql): bool => str_contains($sql, 'public_id')), [
            'userId' => $session->getUserId()->toString(),
            'jobId' => $session->getJobId()->toString(),
            'videoId' => $session->getVideoId()->toString(),
        ])->willReturn(['user_id' => $user->toString(), 'job_id' => $job->toString(), 'video_id' => $video->toString()]);

        $streaming = $this->createStub(TranscodeStreamingPortInterface::class);
        $streaming->method('getPlaybackState')->willReturn(['jobId' => $job->toString(), 'generation' => 8, 'revision' => 4, 'ready' => true, 'position' => 42.0, 'status' => 'paused', 'renditionSet' => $job->toString().'.8', 'startTime' => 0.0, 'duration' => 100.0]);
        $view = (new TranscodePublicView($connection, $streaming, new HmacStreamAuthAdapter('test-secret')))->session($session)->toArray();
        self::assertSame(8, $view['playback']['generation']);
        self::assertSame('paused', $view['playback']['status']);
        self::assertStringContainsString('set='.$job->toString().'.8', $view['playback']['manifestUrl']);

        self::assertSame($session->getPublicId()->toString(), $view['publicId']);
        self::assertSame($user->toString(), $view['userId']);
        self::assertSame($job->toString(), $view['jobId']);
        self::assertSame($video->toString(), $view['videoId']);
        self::assertSame($session->getAudioProfile()->jsonSerialize(), $view['audioProfile']);
        foreach ([$session->getId(), $session->getUserId(), $session->getJobId(), $session->getVideoId()] as $internalId) {
            self::assertStringNotContainsString($internalId->toString(), json_encode($view, JSON_THROW_ON_ERROR));
        }
    }

    public function testJobProjectionDoesNotExposeInternalFilesystemCoordinates(): void
    {
        $job = TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/private-transcode', \App\Shared\Domain\Model\Uuid::generate());
        $job->markFailed('Could not encode video ' . $job->getVideoId()->toString());
        $video = new PublicId();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->with(
            'SELECT public_id FROM videos WHERE id = :id', ['id' => $job->getVideoId()->toString()],
        )->willReturn($video->toString());

        $view = (new TranscodePublicView($connection, $this->createStub(TranscodeStreamingPortInterface::class), new HmacStreamAuthAdapter('test-secret')))->job($job)->toArray();

        self::assertSame($video->toString(), $view['videoId']);
        self::assertSame('Could not encode video [internal ID]', $view['failReason']);
        foreach (['uuid', 'outputDirectory', 'initSegmentPath', 'segmentMap'] as $field) {
            self::assertArrayNotHasKey($field, $view);
        }
        self::assertStringNotContainsString($job->getId()->toString(), json_encode($view, JSON_THROW_ON_ERROR));
    }
}
