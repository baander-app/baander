<?php

declare(strict_types=1);

namespace App\Tests\Functional\Transcode;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Domain\ValueObject\AudioProfile;
use Doctrine\DBAL\Connection;

/** A signed start past the user's share answers 409 with the reason and the limit the player names. */
final class TranscodeSessionLimitResponseTest extends TestCase
{
    public function testASignedStartWhileTheUserHoldsSixPlayingSessionsAnswersTheSessionLimit(): void
    {
        $user = $this->createAdminUser();
        $connection = self::getContainer()->get(Connection::class);
        foreach (range(1, 6) as $ignored) {
            $this->holdPlayingSession($connection, $user->getId());
        }
        $film = $this->video($connection);

        $response = $this->authenticatedRequest('POST', '/api/stream/sign', $user, [
            'path' => '/api/transcode/' . $film->toString() . '/master.m3u8',
            'requestId' => Uuid::generate()->toString(),
        ]);

        $error = $this->assertJsonResponse($response, 409, 'error');
        self::assertSame(409, $error['error']['code']);
        self::assertSame(['reason' => 'transcode_session_limit', 'limit' => 6], $error['error']['details']);
        self::assertSame(6, (int) $connection->fetchOne('SELECT count(*) FROM transcode_sessions WHERE user_id = ?', [$user->getId()->toString()]));
    }

    /** A session another start admitted, its execution running and its last report a moment ago. */
    private function holdPlayingSession(Connection $connection, Uuid $user): void
    {
        [$job, $session, $request, $video] = [Uuid::generate(), Uuid::generate(), Uuid::generate()->toString(), Uuid::generate()];
        $connection->executeStatement(
            'INSERT INTO videos (id, public_id, path, hash, width, height, created_at, updated_at)'
            . " VALUES (:id, :public, '/media/held.mkv', :hash, 1280, 720, clock_timestamp(), clock_timestamp())",
            ['id' => $video->toString(), 'public' => new PublicId()->toString(), 'hash' => random_bytes(16) |> bin2hex(...)],
        );
        $connection->executeStatement(
            'INSERT INTO transcode_jobs (id, public_id, video_id, quality_tier_name, status, reference_count, output_directory, owner_user_id, request_id, created_at, updated_at)'
            . " VALUES (:job, :public, :video, '720p', 'in_progress', 1, '/fixture/limit', :user, :request, clock_timestamp(), clock_timestamp())",
            ['job' => $job->toString(), 'public' => new PublicId()->toString(), 'video' => $video->toString(), 'user' => $user->toString(), 'request' => $request],
        );
        $connection->executeStatement(
            'INSERT INTO transcode_sessions (id, public_id, user_id, job_id, video_id, state, created_at, updated_at)'
            . " VALUES (:session, :public, :user, :job, :video, 'active', clock_timestamp(), clock_timestamp())",
            ['session' => $session->toString(), 'public' => new PublicId()->toString(), 'user' => $user->toString(), 'job' => $job->toString(), 'video' => $video->toString()],
        );
        $ownership = self::getContainer()->get(TranscodeExecutionOwnershipPort::class);
        $connection->transactional(static fn (): mixed => $ownership->admit($job, $session, $request, new DesiredTranscodeState(AudioProfile::streamingStereo())));
        $connection->executeStatement("UPDATE transcode_runtime_state SET outcome = 'accepted' WHERE job_id = ?", [$job->toString()]);
    }

    /** @return PublicId the film's public id, which the manifest path names */
    private function video(Connection $connection): PublicId
    {
        $film = new PublicId();
        $connection->executeStatement(
            'INSERT INTO videos (id, public_id, path, hash, width, height, created_at, updated_at)'
            . " VALUES (:id, :public, '/media/film.mkv', :hash, 1920, 1080, clock_timestamp(), clock_timestamp())",
            ['id' => Uuid::generate()->toString(), 'public' => $film->toString(), 'hash' => random_bytes(16) |> bin2hex(...)],
        );

        return $film;
    }
}
