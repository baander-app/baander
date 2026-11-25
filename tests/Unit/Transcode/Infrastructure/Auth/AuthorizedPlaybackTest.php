<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Auth;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\QoL\Application\Port\AllowedQualityTiersPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Unit\Transcode\FreeTranscodeSessionShare;
use App\Shared\Application\Exception\HandlerFailure;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\DTO\HeldTranscodeSession;
use App\Transcode\Application\DTO\PlaybackEndReason;
use App\Transcode\Application\Exception\TranscodeSessionLimitException;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\TranscodeSessionSharePort;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Auth\AuthorizedPlayback;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AuthorizedPlaybackTest extends TestCase
{
    use FreeTranscodeSessionShare;

    /** @param list<int> $expectedHeights */
    #[DataProvider('actors')]
    public function testPlaybackChecksLibraryMembership(
        string $actor,
        bool $exists,
        bool $allowed,
        ?int $sourceHeight = 1080,
        array $expectedHeights = [360, 720, 1080],
        ?string $qualityTier = null,
        ?int $sourceWidth = 3840,
        bool $unavailable = false,
        bool $qualified = true,
        int $videoSlots = 8,
        int $sessionsPerUser = 6,
    ): void {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE videos (id TEXT PRIMARY KEY, height INTEGER, width INTEGER)');
        $connection->executeStatement('CREATE TABLE movies (id TEXT PRIMARY KEY, library_id TEXT)');
        $connection->executeStatement('CREATE TABLE movie_video (video_id TEXT, movie_id TEXT)');
        $connection->executeStatement('CREATE TABLE user_library_access (user_id TEXT, library_id TEXT)');
        $connection->executeStatement('CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, public_id TEXT, owner_user_id TEXT, video_id TEXT, height INTEGER, status TEXT, request_id TEXT)');
        $connection->executeStatement("CREATE TABLE transcode_sessions (id TEXT PRIMARY KEY, public_id TEXT, user_id TEXT, video_id TEXT, job_id TEXT, updated_at TEXT, state TEXT NOT NULL DEFAULT 'active')");
        $connection->executeStatement('CREATE TABLE transcode_runtime_state (job_id TEXT PRIMARY KEY, session_id TEXT, request_id TEXT)');
        $userId = new Uuid();
        $videoId = new Uuid();
        $requestId = new Uuid()->toString();
        if ($exists) {
            $connection->insert('videos', ['id' => $videoId->toString(), 'height' => $sourceHeight, 'width' => $sourceWidth]);
            $connection->insert('movies', ['id' => 'movie', 'library_id' => 'library']);
            $connection->insert('movie_video', ['video_id' => $videoId->toString(), 'movie_id' => 'movie']);
        }
        $connection->insert('user_library_access', ['user_id' => $userId->toString(), 'library_id' => $actor === 'member' ? 'library' : 'another-library']);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor === 'anonymous' ? null : new SecurityUser($userId->toString(), 'test@baander.app', ''));
        $security->method('isGranted')->willReturn($actor === 'admin');
        $bus = $this->createMock(MessageBusInterface::class);
        $heights = [];
        $bus->expects($allowed && !$unavailable ? $this->exactly(count($expectedHeights)) : $this->never())->method('dispatch')
            ->willReturnCallback(static function (CreateTranscodeSessionCommand $command) use ($userId, $videoId, $requestId, $connection, $qualityTier, &$heights): Envelope {
                self::assertTrue($command->getUserId()->equals($userId));
                self::assertTrue($command->getVideoId()->equals($videoId));
                self::assertSame($requestId, $command->requestId);
                self::assertSame(2, $command->audioStreamIndex);
                self::assertSame(-125, $command->audioOffsetMs);
                if ($qualityTier !== null) {
                    self::assertSame(QualityTier::fromString($qualityTier)->name, $command->getQualityTier()->name);
                }
                $heights[] = $command->getQualityTier()->height;
                $jobId = new Uuid();
                $session = TranscodeSession::create($userId, $jobId, $videoId, AudioProfile::streamingStereo());
                $connection->insert('transcode_jobs', [
                    'id' => $jobId->toString(),
                    'public_id' => new PublicId()->toString(),
                    'owner_user_id' => $userId->toString(),
                    'video_id' => $videoId->toString(),
                    'height' => $command->getQualityTier()->height,
                    'status' => 'pending',
                    'request_id' => $requestId,
                ]);
                $connection->insert('transcode_sessions', [
                    'id' => $session->getId()->toString(),
                    'public_id' => $session->getPublicId()->toString(),
                    'user_id' => $userId->toString(),
                    'video_id' => $videoId->toString(),
                    'job_id' => $jobId->toString(),
                ]);
                $connection->insert('transcode_runtime_state', [
                    'job_id' => $jobId->toString(),
                    'session_id' => $session->getId()->toString(),
                    'request_id' => $requestId,
                ]);
                return new Envelope($command, [new HandledStamp($session, 'test-handler')]);
            });
        $playback = new AuthorizedPlayback(
            $security,
            $connection,
            $bus,
            new MockClock(),
            $this->qualityTiers($qualified),
            $this->freeSessionShare(sessionsPerUser: $sessionsPerUser),
            new DeploymentProfile(videoEncoderSlots: $videoSlots),
        );
        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
        } elseif ($unavailable) {
            $this->expectException(TranscodeStartupUnavailableException::class);
        }
        try {
            self::assertCount(count($expectedHeights), $playback->start($videoId, $requestId, 2, -125, $qualityTier));
            self::assertSame($expectedHeights, $heights);
        } finally {
            $connection->close();
        }
    }

    /** @return iterable<array{0: string, 1: bool, 2: bool, 3?: ?int, 4?: list<int>, 5?: ?string, 6?: ?int, 7?: bool, 8?: bool, 9?: int, 10?: int}> */
    public static function actors(): iterable
    {
        yield ['member', true, true];
        yield ['unrelated', true, false];
        yield ['admin', true, true];
        yield ['anonymous', true, false];
        yield ['admin', false, false];
        yield ['member', false, false];
        yield 'one video slot admits only the automatic baseline' => [
            'member', true, true, 1080, [360], null, 3840, false, true, 1,
        ];
        yield 'two video slots admit two automatic tiers' => [
            'member', true, true, 1080, [360, 720], null, 3840, false, true, 2,
        ];
        yield 'one video slot preserves the exact manual tier' => [
            'member', true, true, 1080, [1080], '1080p', 1920, false, true, 1,
        ];
        yield 'a share of two sessions admits two automatic tiers' => [
            'member', true, true, 1080, [360, 720], null, 3840, false, true, 3, 2,
        ];
        yield 'a share of one session admits only the automatic baseline' => [
            'admin', true, true, 2160, [360], null, 3840, false, true, 3, 1,
        ];
        yield 'source below minimum tier' => ['member', true, true, 180, [360]];
        yield 'source at minimum tier' => ['member', true, true, 360, [360]];
        yield 'source between admitted tiers' => ['member', true, true, 480, [360]];
        yield 'source at middle tier' => ['member', true, true, 720, [360, 720]];
        yield 'large source keeps existing tier set' => ['member', true, true, 2160, [360, 720, 1080]];
        yield 'unknown source height uses minimum tier' => ['member', true, true, null, [360]];
        foreach (['360p' => 360, '480p' => 480, '720p' => 720, '1080p' => 1080, '1440p' => 1440, '4K' => 2160, '2160p' => 2160, '4k' => 2160] as $tier => $height) {
            yield 'manual ' . $tier => ['member', true, true, 2160, [$height], $tier];
        }
        yield 'manual tier fits exact source dimensions' => ['member', true, true, 720, [720], '720p', 1280];
        yield 'manual source height unknown' => ['member', true, true, null, [], '360p', 1920, true];
        yield 'manual source width unknown' => ['member', true, true, 1080, [], '360p', null, true];
        yield 'manual source below requested height' => ['member', true, true, 719, [], '720p', 1920, true];
        yield 'manual source below requested width' => ['member', true, true, 1080, [], '720p', 1279, true];
        yield 'manual portrait source does not fit target width' => ['member', true, true, 1920, [], '1080p', 1080, true];
        yield 'manual source below minimum tier' => ['member', true, true, 180, [], '360p', 320, true];
        yield 'manual zero source dimension' => ['member', true, true, 0, [], '360p', 640, true];
        yield 'manual tier no longer qualified' => ['member', true, true, 2160, [], '720p', 3840, true, false];
        yield 'manual still requires library access' => ['unrelated', true, false, 2160, [], '720p'];

    }

    public function testATierRefusedForTheUsersShareCancelsOnlyTheSessionsTheStartCreated(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE videos (id TEXT PRIMARY KEY, height INTEGER, width INTEGER)');
        $userId = new Uuid();
        $videoId = new Uuid();
        $requestId = new Uuid()->toString();
        $connection->insert('videos', ['id' => $videoId->toString(), 'height' => 1080, 'width' => 1920]);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($userId->toString(), 'test@baander.app', ''));
        $security->method('isGranted')->willReturn(true);
        $handedBack = TranscodeSession::create($userId, new Uuid(), $videoId, AudioProfile::streamingStereo());
        $created = TranscodeSession::create($userId, new Uuid(), $videoId, AudioProfile::streamingStereo());
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (CreateTranscodeSessionCommand $command) use ($handedBack, $created): Envelope {
            $envelope = new Envelope($command);

            return match ($command->getQualityTier()->name) {
                '360p' => $envelope->with(new HandledStamp($handedBack, 'test-handler')),
                '720p' => $envelope->with(new HandledStamp($created, 'test-handler')),
                default => throw new HandlerFailedException($envelope, [TranscodeSessionLimitException::at(6)]),
            };
        });
        $store = $this->createMock(TranscodeSessionSharePort::class);
        $store->method('heldBy')->willReturn([
            new HeldTranscodeSession($handedBack->getId(), $handedBack->getJobId(), $videoId, $requestId, '360p', new \DateTimeImmutable(), true),
        ]);
        $store->method('lock')->willReturnCallback(static fn (Uuid $session): HeldTranscodeSession => new HeldTranscodeSession($session, $created->getJobId(), $videoId, $requestId, '720p', new \DateTimeImmutable(), false));
        $store->expects(self::once())->method('end')->with(self::callback(static fn (HeldTranscodeSession $session): bool => $session->sessionId->equals($created->getId())));
        $playback = new AuthorizedPlayback($security, $connection, $bus, new MockClock(), $this->qualityTiers(), $this->freeSessionShare($store));

        try {
            $playback->start($videoId, $requestId);
            self::fail('The third tier was refused.');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(TranscodeSessionLimitException::class, HandlerFailure::cause($failure));
        } finally {
            $connection->close();
        }
    }

    public function testSigningRenewsOnlyAnAgedSignOfLifeOfTheActorsOwnSessions(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE transcode_sessions (id TEXT PRIMARY KEY, public_id TEXT, user_id TEXT, updated_at TEXT)');
        $actor = new Uuid();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($actor->toString(), 'viewer@baander.app', ''));
        // A paused player signs every second; its last write was two hours ago.
        $paused = new PublicId();
        // A playing session's report renewed it a moment ago.
        $playing = new PublicId();
        $foreign = new PublicId();
        $connection->insert('transcode_sessions', [
            'id' => 'paused',
            'public_id' => $paused->toString(),
            'user_id' => $actor->toString(),
            'updated_at' => '2026-11-10 10:00:00.000000+00:00',
        ]);
        $connection->insert('transcode_sessions', [
            'id' => 'playing',
            'public_id' => $playing->toString(),
            'user_id' => $actor->toString(),
            'updated_at' => '2026-11-10 11:59:58.000000+00:00',
        ]);
        $connection->insert('transcode_sessions', [
            'id' => 'foreign',
            'public_id' => $foreign->toString(),
            'user_id' => new Uuid()->toString(),
            'updated_at' => '2026-11-10 10:00:00.000000+00:00',
        ]);
        $clock = new MockClock(new \DateTimeImmutable('2026-11-10 12:00:00', new \DateTimeZone('UTC')));
        $playback = new AuthorizedPlayback($security, $connection, $this->createStub(MessageBusInterface::class), $clock, $this->qualityTiers(), $this->freeSessionShare());
        $signs = static fn(string $id): string => (string) $connection->fetchOne('SELECT updated_at FROM transcode_sessions WHERE id = :id', ['id' => $id]);
        try {
            $playback->touchSessions([$paused, $playing, $foreign]);

            self::assertSame('2026-11-10 12:00:00.000000+00:00', $signs('paused'));
            self::assertSame('2026-11-10 11:59:58.000000+00:00', $signs('playing'), 'A session renewed within the interval is not written.');
            self::assertSame('2026-11-10 10:00:00.000000+00:00', $signs('foreign'), 'Another user\'s session is never renewed.');

            // The next signings inside the interval write nothing.
            $clock->modify('2026-11-10 12:59:59');
            $playback->touchSessions([$paused]);
            self::assertSame('2026-11-10 12:00:00.000000+00:00', $signs('paused'));
            $clock->modify('2026-11-10 13:00:01');
            $playback->touchSessions([$paused]);
            self::assertSame('2026-11-10 13:00:01.000000+00:00', $signs('paused'));

            $playback->touchSessions([]);
        } finally {
            $connection->close();
        }
    }

    public function testControlsExposeOnlyTheActorsCurrentRuntimeSession(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, public_id TEXT, owner_user_id TEXT, video_id TEXT, height INTEGER, status TEXT, request_id TEXT, presentation_selection TEXT)');
        $connection->executeStatement("CREATE TABLE transcode_sessions (id TEXT PRIMARY KEY, public_id TEXT, user_id TEXT, video_id TEXT, job_id TEXT, updated_at TEXT, state TEXT NOT NULL DEFAULT 'active')");
        $connection->executeStatement('CREATE TABLE transcode_runtime_state (job_id TEXT PRIMARY KEY, session_id TEXT, request_id TEXT)');
        $actor = new Uuid();
        $video = new Uuid();
        $job = new PublicId();
        $session = new PublicId();
        $requestId = new Uuid()->toString();
        $otherRequestId = new Uuid()->toString();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($actor->toString(), 'viewer@baander.app', ''));
        $connection->insert('transcode_jobs', [
            'id' => 'job',
            'public_id' => $job->toString(),
            'owner_user_id' => $actor->toString(),
            'video_id' => $video->toString(),
            'height' => 720,
            'status' => 'in_progress',
            'request_id' => $requestId,
        ]);
        $connection->insert('transcode_sessions', [
            'id' => 'session',
            'public_id' => $session->toString(),
            'user_id' => $actor->toString(),
            'video_id' => $video->toString(),
            'job_id' => 'job',
        ]);
        $connection->insert('transcode_runtime_state', ['job_id' => 'job', 'session_id' => 'session', 'request_id' => $requestId]);
        $connection->insert('transcode_jobs', [
            'id' => 'other-job',
            'public_id' => new PublicId()->toString(),
            'owner_user_id' => $actor->toString(),
            'video_id' => $video->toString(),
            'height' => 720,
            'status' => 'in_progress',
            'request_id' => $otherRequestId,
        ]);
        $connection->insert('transcode_sessions', [
            'id' => 'other-session',
            'public_id' => new PublicId()->toString(),
            'user_id' => $actor->toString(),
            'video_id' => $video->toString(),
            'job_id' => 'other-job',
        ]);
        $connection->insert('transcode_runtime_state', ['job_id' => 'other-job', 'session_id' => 'other-session', 'request_id' => $otherRequestId]);
        $copyJob = new PublicId();
        $copySession = new PublicId();
        $connection->insert('transcode_jobs', [
            'id' => 'copy-job',
            'public_id' => $copyJob->toString(),
            'owner_user_id' => $actor->toString(),
            'video_id' => $video->toString(),
            'height' => 720,
            'status' => 'in_progress',
            'request_id' => $requestId,
            'presentation_selection' => '{"mode":"copy"}',
        ]);
        $connection->insert('transcode_sessions', [
            'id' => 'copy-session',
            'public_id' => $copySession->toString(),
            'user_id' => $actor->toString(),
            'video_id' => $video->toString(),
            'job_id' => 'copy-job',
        ]);
        $connection->insert('transcode_runtime_state', ['job_id' => 'copy-job', 'session_id' => 'copy-session', 'request_id' => $requestId]);
        $playback = new AuthorizedPlayback($security, $connection, $this->createStub(MessageBusInterface::class), new MockClock(), $this->qualityTiers(), $this->freeSessionShare());
        try {
            self::assertSame([$job->toString()], array_keys($this->currentSessions($playback, $video, $requestId, $job)));
            self::assertSame($session->toString(), $this->currentSessions($playback, $video, $requestId, $job)[$job->toString()]->toString());
            self::assertEquals([$copyJob->toString() => $copySession], $playback->findSessions($video, $requestId, [$copyJob]));
            // The lookup also serves the quality ladder, which is no sign of life: it writes nothing.
            self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM transcode_sessions WHERE updated_at IS NOT NULL'));
            self::assertSame([], $playback->findSessions($video, $otherRequestId, [$copyJob]));
            self::assertSame([], $playback->findSessions($video, $requestId, [new PublicId()]));
            self::assertSame([], $playback->findSessions($video, $requestId, []));
            $connection->update('transcode_sessions', ['user_id' => new Uuid()->toString()], ['id' => 'session']);
            self::assertSame([], $this->currentSessions($playback, $video, $requestId, $job));
            $connection->update('transcode_sessions', ['user_id' => $actor->toString()], ['id' => 'session']);
            $connection->update('transcode_runtime_state', ['session_id' => 'retired-session'], ['job_id' => 'job']);
            self::assertSame([], $this->currentSessions($playback, $video, $requestId, $job));
        } finally {
            $connection->close();
        }
    }

    /**
     * @param array<int, array{job: ?string, session: string, outcome: string}> $jobs Job status (null when the job is gone), session state and execution outcome by tier height
     */
    #[DataProvider('endedSessions')]
    public function testEndedSessionsAreNotHeldAndSayWhyTheyEnded(array $jobs, ?PlaybackEndReason $reason): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, public_id TEXT, owner_user_id TEXT, video_id TEXT, height INTEGER, status TEXT, request_id TEXT)');
        $connection->executeStatement('CREATE TABLE transcode_sessions (id TEXT PRIMARY KEY, public_id TEXT, user_id TEXT, video_id TEXT, job_id TEXT, updated_at TEXT, state TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE transcode_runtime_state (job_id TEXT PRIMARY KEY, session_id TEXT, request_id TEXT, outcome TEXT)');
        $actor = new Uuid();
        $video = new Uuid();
        $requestId = new Uuid()->toString();
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($actor->toString(), 'viewer@baander.app', ''));
        $named = [];
        $held = [];
        foreach ($jobs as $height => $row) {
            $job = new PublicId();
            $named[] = $job;
            if ($row['job'] === null) {
                continue;
            }
            $connection->insert('transcode_jobs', [
                'id' => 'job-' . $height,
                'public_id' => $job->toString(),
                'owner_user_id' => $actor->toString(),
                'video_id' => $video->toString(),
                'height' => $height,
                'status' => $row['job'],
                'request_id' => $requestId,
            ]);
            $connection->insert('transcode_sessions', [
                'id' => 'session-' . $height,
                'public_id' => new PublicId()->toString(),
                'user_id' => $actor->toString(),
                'video_id' => $video->toString(),
                'job_id' => 'job-' . $height,
                'state' => $row['session'],
            ]);
            $connection->insert('transcode_runtime_state', [
                'job_id' => 'job-' . $height,
                'session_id' => 'session-' . $height,
                'request_id' => $requestId,
                'outcome' => $row['outcome'],
            ]);
            if (in_array($row['job'], ['pending', 'in_progress', 'completed'], true) && !in_array($row['session'], ['cancelled', 'failed'], true)) {
                $held[] = $job->toString();
            }
        }
        // Another client's failed job for the same video never explains this client's sessions.
        $connection->insert('transcode_jobs', [
            'id' => 'other',
            'public_id' => new PublicId()->toString(),
            'owner_user_id' => $actor->toString(),
            'video_id' => $video->toString(),
            'height' => 360,
            'status' => 'failed',
            'request_id' => new Uuid()->toString(),
        ]);
        $playback = new AuthorizedPlayback($security, $connection, $this->createStub(MessageBusInterface::class), new MockClock(), $this->qualityTiers(), $this->freeSessionShare());
        try {
            self::assertSame($held, array_keys($playback->findSessions($video, $requestId, $named)));
            if ($reason !== null) {
                self::assertSame($reason, $playback->endReason($video, $requestId, $named));
            }
        } finally {
            $connection->close();
        }
    }

    /** @return iterable<string, array{array<int, array{job: ?string, session: string, outcome: string}>, ?PlaybackEndReason}> */
    public static function endedSessions(): iterable
    {
        $row = static fn(?string $job, string $session, string $outcome): array => ['job' => $job, 'session' => $session, 'outcome' => $outcome];
        yield 'held sessions' => [[360 => $row('in_progress', 'active', 'ready'), 720 => $row('completed', 'paused', 'complete')], null];
        yield 'expired, before job cleanup' => [[360 => $row('in_progress', 'cancelled', 'cancelled'), 720 => $row('pending', 'cancelled', 'cancelled')], PlaybackEndReason::Expired];
        yield 'jobs removed by cleanup' => [[360 => $row(null, 'cancelled', 'cancelled'), 720 => $row(null, 'cancelled', 'cancelled')], PlaybackEndReason::Expired];
        yield 'one rendition expired, another still held' => [[360 => $row('in_progress', 'cancelled', 'cancelled'), 720 => $row('in_progress', 'active', 'ready')], null];
        yield 'failed job' => [[360 => $row('failed', 'active', 'failed'), 720 => $row('in_progress', 'cancelled', 'cancelled')], PlaybackEndReason::Failed];
        yield 'refused execution' => [[360 => $row('pending', 'cancelled', 'refused')], PlaybackEndReason::Failed];
        yield 'session failed after an interruption' => [[360 => $row('in_progress', 'failed', 'accepted')], PlaybackEndReason::Failed];
        yield 'cancelled job' => [[360 => $row('cancelled', 'cancelled', 'cancelled'), 720 => $row('cancelled', 'cancelled', 'cancelled')], PlaybackEndReason::Cancelled];
        yield 'cancelled session of a finished presentation' => [[360 => $row('completed', 'cancelled', 'cancelled')], PlaybackEndReason::Cancelled];
        yield 'finished presentation that gave way to another start' => [[360 => $row('completed', 'cancelled', 'complete')], PlaybackEndReason::Expired];
    }

    private function qualityTiers(bool $qualified = true): AllowedQualityTiersPortInterface
    {
        $tiers = $this->createStub(AllowedQualityTiersPortInterface::class);
        $tiers->method('allowedTierNames')->willReturn([]);
        $tiers->method('qualifiedTierNames')->willReturn($qualified ? ['360p', '480p', '720p', '1080p', '1440p', '4K'] : []);
        return $tiers;
    }

    /**
     * @return array<string, PublicId>
     * @phpstan-impure
     */
    private function currentSessions(AuthorizedPlayback $playback, Uuid $video, string $requestId, PublicId $jobId): array
    {
        return $playback->findSessions($video, $requestId, [$jobId]);
    }

    public function testFindsTheVideoOnlyOfAJobTheGivenUserOwns(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE transcode_jobs (id TEXT PRIMARY KEY, video_id TEXT NOT NULL, owner_user_id TEXT)');
        $owner = new Uuid();
        $jobId = new Uuid();
        $ownerlessJobId = new Uuid();
        $videoId = new Uuid();
        $connection->insert('transcode_jobs', ['id' => $jobId->toString(), 'video_id' => $videoId->toString(), 'owner_user_id' => $owner->toString()]);
        $connection->insert('transcode_jobs', ['id' => $ownerlessJobId->toString(), 'video_id' => $videoId->toString(), 'owner_user_id' => null]);
        $playback = new AuthorizedPlayback($this->createStub(Security::class), $connection, $this->createStub(MessageBusInterface::class), new MockClock(), $this->qualityTiers(), $this->freeSessionShare());

        try {
            self::assertTrue($playback->findOwnedTranscodeJobVideoId($jobId, $owner)?->equals($videoId));
            self::assertNull($playback->findOwnedTranscodeJobVideoId($jobId, new Uuid()), 'Another user\'s job is not found.');
            self::assertNull($playback->findOwnedTranscodeJobVideoId($ownerlessJobId, $owner), 'A job without an owner is nobody\'s.');
            self::assertNull($playback->findOwnedTranscodeJobVideoId(new Uuid(), $owner));
        } finally {
            $connection->close();
        }
    }
}
