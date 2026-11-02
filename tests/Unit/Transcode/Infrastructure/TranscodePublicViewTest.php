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
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Transcode\Infrastructure\Auth\HmacStreamAuthAdapter;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class TranscodePublicViewTest extends TestCase
{
    /** @return iterable<string, array{bool, string, bool, string}> */
    public static function publicFailureStates(): iterable
    {
        yield 'pending admission refused' => [false, 'refused', true, 'failed'];
        yield 'pending worker failed' => [false, 'failed', true, 'failed'];
        yield 'completed encoding reactivation rejected' => [true, 'failed', true, 'failed'];
        yield 'replacement session failure is not ours' => [true, 'failed', false, 'completed'];
        yield 'permitted copy can reactivate' => [true, 'ready', true, 'completed'];
    }

    #[DataProvider('publicFailureStates')]
    public function testOnlyMatchingRuntimeFailureOverridesTheSessionProjection(bool $completed, string $outcome, bool $sameSession, string $state): void
    {
        $session = TranscodeSession::create(new Uuid(), new Uuid(), new Uuid(), AudioProfile::streamingStereo());
        if ($completed) {
            $session->markPreparing();
            $session->markActive();
            $session->markCompleted();
        }
        $originalState = $session->getSessionState();
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['user_id' => (new PublicId())->toString(), 'job_id' => (new PublicId())->toString(), 'video_id' => (new PublicId())->toString()]);
        $ownership = $this->createMock(TranscodeExecutionOwnershipPort::class);
        $ownership->expects(self::once())->method('find')->with($session->getJobId())->willReturn(new TranscodeRuntimeSnapshot(
            $session->getJobId(), $sameSession ? $session->getId() : new Uuid(), (new Uuid())->toString(),
            new DesiredTranscodeState(AudioProfile::streamingStereo()), 2, 2, 4, $outcome, null,
            'Encoding is prohibited for this deployment profile.',
        ));
        $view = (new TranscodePublicView($connection, $this->createStub(TranscodeStreamingPortInterface::class), new HmacStreamAuthAdapter('test-secret'), $ownership))->session($session)->toArray();
        self::assertSame($state, $view['state']);
        self::assertSame($originalState, $session->getSessionState());
        self::assertArrayNotHasKey('error', $view);
    }

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
        $view = (new TranscodePublicView($connection, $streaming, new HmacStreamAuthAdapter('test-secret'), $this->createStub(TranscodeExecutionOwnershipPort::class)))->session($session)->toArray();
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

        $view = (new TranscodePublicView($connection, $this->createStub(TranscodeStreamingPortInterface::class), new HmacStreamAuthAdapter('test-secret'), $this->createStub(TranscodeExecutionOwnershipPort::class)))->job($job)->toArray();

        self::assertSame($video->toString(), $view['videoId']);
        self::assertSame('Could not encode video [internal ID]', $view['failReason']);
        foreach (['uuid', 'outputDirectory', 'initSegmentPath', 'segmentMap'] as $field) {
            self::assertArrayNotHasKey($field, $view);
        }
        self::assertStringNotContainsString($job->getId()->toString(), json_encode($view, JSON_THROW_ON_ERROR));
    }
}
