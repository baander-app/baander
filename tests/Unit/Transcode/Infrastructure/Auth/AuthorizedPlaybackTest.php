<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Auth;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Infrastructure\Auth\AuthorizedPlayback;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AuthorizedPlaybackTest extends TestCase
{
    /** @param list<int> $expectedHeights */
    #[DataProvider('actors')]
    /** @param list<int> $expectedHeights */
    public function testPlaybackChecksLibraryMembership(string $actor, bool $exists, bool $allowed, ?int $sourceHeight = 1080, array $expectedHeights = [360, 720, 1080]): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE videos (id TEXT PRIMARY KEY, height INTEGER)');
        $connection->executeStatement('CREATE TABLE movies (id TEXT PRIMARY KEY, library_id TEXT)');
        $connection->executeStatement('CREATE TABLE movie_video (video_id TEXT, movie_id TEXT)');
        $connection->executeStatement('CREATE TABLE user_library_access (user_id TEXT, library_id TEXT)');
        $connection->executeStatement('CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, public_id TEXT, owner_user_id TEXT, video_id TEXT, height INTEGER, status TEXT, request_id TEXT)');
        $connection->executeStatement('CREATE TABLE transcode_sessions (id TEXT PRIMARY KEY, public_id TEXT, user_id TEXT, video_id TEXT, job_id TEXT, updated_at TEXT)');
        $connection->executeStatement('CREATE TABLE transcode_runtime_state (job_id TEXT PRIMARY KEY, session_id TEXT, request_id TEXT)');
        $userId = new Uuid();
        $videoId = new Uuid();
        $requestId = new Uuid()->toString();
        if ($exists) {
            $connection->insert('videos', ['id' => $videoId->toString(), 'height' => $sourceHeight]);
            $connection->insert('movies', ['id' => 'movie', 'library_id' => 'library']);
            $connection->insert('movie_video', ['video_id' => $videoId->toString(), 'movie_id' => 'movie']);
        }
        $connection->insert('user_library_access', ['user_id' => $userId->toString(), 'library_id' => $actor === 'member' ? 'library' : 'another-library']);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor === 'anonymous' ? null : new SecurityUser($userId->toString(), 'test@baander.app', ''));
        $security->method('isGranted')->willReturn($actor === 'admin');
        $bus = $this->createMock(MessageBusInterface::class);
        $heights = [];
        $bus->expects($allowed ? $this->exactly(count($expectedHeights)) : $this->never())->method('dispatch')
            ->willReturnCallback(static function (CreateTranscodeSessionCommand $command) use ($userId, $videoId, $requestId, $connection, &$heights): Envelope {
                self::assertTrue($command->getUserId()->equals($userId));
                self::assertTrue($command->getVideoId()->equals($videoId));
                self::assertSame($requestId, $command->requestId);
                $heights[] = $command->getQualityTier()->height;
                $jobId = new Uuid();
                $session = TranscodeSession::create($userId, $jobId, $videoId, AudioProfile::streamingStereo());
                $connection->insert('transcode_jobs', ['id' => $jobId->toString(), 'public_id' => new PublicId()->toString(), 'owner_user_id' => $userId->toString(), 'video_id' => $videoId->toString(), 'height' => $command->getQualityTier()->height, 'status' => 'pending', 'request_id' => $requestId]);
                $connection->insert('transcode_sessions', ['id' => $session->getId()->toString(), 'public_id' => $session->getPublicId()->toString(), 'user_id' => $userId->toString(), 'video_id' => $videoId->toString(), 'job_id' => $jobId->toString()]);
                $connection->insert('transcode_runtime_state', ['job_id' => $jobId->toString(), 'session_id' => $session->getId()->toString(), 'request_id' => $requestId]);
                return new Envelope($command, [new HandledStamp($session, 'test-handler')]);
            });
        $playback = new AuthorizedPlayback($security, $connection, $bus);
        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
        }
        try {
            self::assertCount(count($expectedHeights), $playback->start($videoId, $requestId));
            self::assertSame($expectedHeights, $heights);
        } finally {
            $connection->close();
        }
    }

    /** @return iterable<array{string, bool, bool}|array{string, bool, bool, ?int, list<int>}> */
    public static function actors(): iterable
    {
        yield ['member', true, true];
        yield ['unrelated', true, false];
        yield ['admin', true, true];
        yield ['anonymous', true, false];
        yield ['admin', false, false];
        yield ['member', false, false];
        yield 'source below minimum tier' => ['member', true, true, 180, [360]];
        yield 'source at minimum tier' => ['member', true, true, 360, [360]];
        yield 'source between admitted tiers' => ['member', true, true, 480, [360]];
        yield 'source at middle tier' => ['member', true, true, 720, [360, 720]];
        yield 'large source keeps existing tier set' => ['member', true, true, 2160, [360, 720, 1080]];
        yield 'unknown source height uses minimum tier' => ['member', true, true, null, [360]];
    }

    public function testControlsExposeOnlyTheActorsCurrentRuntimeSession(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, public_id TEXT, owner_user_id TEXT, video_id TEXT, height INTEGER, status TEXT, request_id TEXT, presentation_selection TEXT)');
        $connection->executeStatement('CREATE TABLE transcode_sessions (id TEXT PRIMARY KEY, public_id TEXT, user_id TEXT, video_id TEXT, job_id TEXT, updated_at TEXT)');
        $connection->executeStatement('CREATE TABLE transcode_runtime_state (job_id TEXT PRIMARY KEY, session_id TEXT, request_id TEXT)');
        $actor = new Uuid();
        $video = new Uuid();
        $job = new PublicId();
        $session = new PublicId();
        $requestId = new Uuid()->toString();
        $otherRequestId = new Uuid()->toString();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($actor->toString(), 'viewer@baander.app', ''));
        $connection->insert('transcode_jobs', ['id' => 'job', 'public_id' => $job->toString(), 'owner_user_id' => $actor->toString(), 'video_id' => $video->toString(), 'height' => 720, 'status' => 'in_progress', 'request_id' => $requestId]);
        $connection->insert('transcode_sessions', ['id' => 'session', 'public_id' => $session->toString(), 'user_id' => $actor->toString(), 'video_id' => $video->toString(), 'job_id' => 'job']);
        $connection->insert('transcode_runtime_state', ['job_id' => 'job', 'session_id' => 'session', 'request_id' => $requestId]);
        $connection->insert('transcode_jobs', ['id' => 'other-job', 'public_id' => new PublicId()->toString(), 'owner_user_id' => $actor->toString(), 'video_id' => $video->toString(), 'height' => 720, 'status' => 'in_progress', 'request_id' => $otherRequestId]);
        $connection->insert('transcode_sessions', ['id' => 'other-session', 'public_id' => new PublicId()->toString(), 'user_id' => $actor->toString(), 'video_id' => $video->toString(), 'job_id' => 'other-job']);
        $connection->insert('transcode_runtime_state', ['job_id' => 'other-job', 'session_id' => 'other-session', 'request_id' => $otherRequestId]);
        $copyJob = new PublicId();
        $copySession = new PublicId();
        $connection->insert('transcode_jobs', ['id' => 'copy-job', 'public_id' => $copyJob->toString(), 'owner_user_id' => $actor->toString(), 'video_id' => $video->toString(), 'height' => 720, 'status' => 'in_progress', 'request_id' => $requestId, 'presentation_selection' => '{"mode":"copy"}']);
        $connection->insert('transcode_sessions', ['id' => 'copy-session', 'public_id' => $copySession->toString(), 'user_id' => $actor->toString(), 'video_id' => $video->toString(), 'job_id' => 'copy-job']);
        $connection->insert('transcode_runtime_state', ['job_id' => 'copy-job', 'session_id' => 'copy-session', 'request_id' => $requestId]);
        $playback = new AuthorizedPlayback($security, $connection, $this->createStub(MessageBusInterface::class));
        try {
            self::assertSame([$job->toString()], array_keys($this->currentSessions($playback, $video, $requestId, $job)));
            self::assertSame($session->toString(), $this->currentSessions($playback, $video, $requestId, $job)[$job->toString()]->toString());
            // Signing is the session's sign of life while a paused player sends no reports.
            self::assertNotNull($connection->fetchOne("SELECT updated_at FROM transcode_sessions WHERE id = 'session'"));
            self::assertNull($connection->fetchOne("SELECT updated_at FROM transcode_sessions WHERE id = 'copy-session'"));
            self::assertNull($connection->fetchOne("SELECT updated_at FROM transcode_sessions WHERE id = 'other-session'"));
            self::assertEquals([$copyJob->toString() => $copySession], $playback->findSessions($video, $requestId, [$copyJob]));
            self::assertNotNull($connection->fetchOne("SELECT updated_at FROM transcode_sessions WHERE id = 'copy-session'"));
            self::assertSame([], $playback->findSessions($video, $otherRequestId, [$copyJob]));
            self::assertSame([], $playback->findSessions($video, $requestId, [new PublicId()]));
            self::assertSame([], $playback->findSessions($video, $requestId, []));
            $connection->update('transcode_sessions', ['user_id' => (new Uuid())->toString()], ['id' => 'session']);
            self::assertSame([], $this->currentSessions($playback, $video, $requestId, $job));
            $connection->update('transcode_sessions', ['user_id' => $actor->toString()], ['id' => 'session']);
            $connection->update('transcode_runtime_state', ['session_id' => 'retired-session'], ['job_id' => 'job']);
            self::assertSame([], $this->currentSessions($playback, $video, $requestId, $job));
        } finally {
            $connection->close();
        }
    }

    /**
     * @return array<string, PublicId>
     * @phpstan-impure
     */
    private function currentSessions(AuthorizedPlayback $playback, Uuid $video, string $requestId, PublicId $jobId): array
    {
        return $playback->findSessions($video, $requestId, [$jobId]);
    }

    public function testFindsTheVideoATranscodeJobEncodes(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, video_id TEXT NOT NULL)');
        $jobId = new Uuid();
        $videoId = new Uuid();
        $connection->insert('transcode_jobs', ['id' => $jobId->toString(), 'video_id' => $videoId->toString()]);
        $playback = new AuthorizedPlayback($this->createStub(Security::class), $connection, $this->createStub(MessageBusInterface::class));

        try {
            self::assertTrue($playback->findTranscodeJobVideoId($jobId)?->equals($videoId));
            self::assertNull($playback->findTranscodeJobVideoId(new Uuid()));
        } finally {
            $connection->close();
        }
    }
}
