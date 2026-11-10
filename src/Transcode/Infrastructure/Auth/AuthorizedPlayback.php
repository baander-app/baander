<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Auth;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionPriority;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AuthorizedPlayback implements PlaybackPortInterface
{
    /**
     * How long a signing leaves a session's sign of life alone. Far below the
     * idle bound of stale-session expiry, so a paused player that signs every
     * second writes once an hour, and a playing one, whose reports already
     * renew the session every second, never writes here.
     */
    private const int SIGN_OF_LIFE_INTERVAL_SECONDS = 3600;

    public function __construct(
        private Security $security,
        private Connection $connection,
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
    ) {
    }

    public function assertAccess(Uuid $videoId): void
    {
        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw new AccessDeniedException('Authentication required.');
        }
        $params = ['videoId' => $videoId->toString()];
        $sql = 'SELECT 1 FROM videos v WHERE v.id = :videoId';
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            $sql .= ' AND EXISTS (SELECT 1 FROM movie_video mv'
                .' JOIN movies m ON m.id = mv.movie_id'
                .' JOIN user_library_access a ON a.library_id = m.library_id'
                .' WHERE mv.video_id = v.id AND a.user_id = :userId)';
            $params['userId'] = $user->getId();
        }
        if ($this->connection->fetchOne($sql, $params) === false) {
            throw new AccessDeniedException('Video is not accessible.');
        }
    }

    public function findTranscodeJobVideoId(Uuid $jobId): ?Uuid
    {
        $videoId = $this->connection->fetchOne('SELECT video_id FROM transcode_jobs WHERE id = :jobId', ['jobId' => $jobId->toString()]);

        return is_string($videoId) ? Uuid::fromString($videoId) : null;
    }

    public function findSessions(Uuid $videoId, string $requestId, array $jobIds): array
    {
        return $this->sessionsForJobs($videoId, $requestId, array_map(static fn (PublicId $id): string => $id->toString(), $jobIds), false);
    }

    /**
     * @param list<string> $jobIds
     * @return array<string, PublicId>
     */
    private function sessionsForJobs(Uuid $videoId, string $requestId, array $jobIds, bool $internalIds): array
    {
        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw new AccessDeniedException('Authentication required.');
        }
        if ($jobIds === []) {
            return [];
        }
        $selection = $internalIds ? ' AND j.id IN (:jobs)' : ' AND j.public_id IN (:jobs)';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT j.public_id AS job_id, s.public_id AS session_id FROM transcode_sessions s'
            .' JOIN transcode_jobs j ON j.id = s.job_id'
            .' JOIN transcode_runtime_state r ON r.session_id = s.id AND r.job_id = j.id'
            .' WHERE s.user_id = :user AND j.owner_user_id = :user AND s.video_id = :video AND j.video_id = :video'
            .' AND j.request_id = :request AND r.request_id = :request'
            .$selection
            ." AND j.status IN ('pending', 'in_progress', 'completed') ORDER BY j.height, j.public_id",
            ['user' => $user->getId(), 'video' => $videoId->toString(), 'request' => $requestId, 'jobs' => $jobIds],
            ['jobs' => ArrayParameterType::STRING],
        );
        $sessions = [];
        foreach ($rows as $row) {
            $sessions[$row['job_id']] = PublicId::fromString($row['session_id']);
        }
        return $sessions;
    }

    public function touchSessions(array $sessionIds): void
    {
        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw new AccessDeniedException('Authentication required.');
        }
        if ($sessionIds === []) {
            return;
        }
        $now = $this->clock->now();
        // Only a session whose sign of life has aged is written. A session a
        // playback report just renewed does not match and is not locked, so
        // signing takes no row lock beside a running playback control.
        $this->connection->executeStatement(
            'UPDATE transcode_sessions SET updated_at = :now'
            .' WHERE public_id IN (:sessions) AND user_id = :user AND updated_at < :aged',
            [
                'now' => $now,
                'aged' => $now->modify(sprintf('-%d seconds', self::SIGN_OF_LIFE_INTERVAL_SECONDS)),
                'sessions' => array_map(static fn (PublicId $id): string => $id->toString(), $sessionIds),
                'user' => $user->getId(),
            ],
            ['now' => Types::DATETIMETZ_IMMUTABLE, 'aged' => Types::DATETIMETZ_IMMUTABLE, 'sessions' => ArrayParameterType::STRING],
        );
    }

    public function start(Uuid $videoId, string $requestId): array
    {
        $this->assertAccess($videoId);
        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw new AccessDeniedException('Authentication required.');
        }
        $sourceHeight = $this->connection->fetchOne('SELECT height FROM videos WHERE id = :videoId', ['videoId' => $videoId->toString()]);
        if ($sourceHeight === false) {
            throw new TranscodeStartupUnavailableException();
        }
        $tiers = array_filter([QualityTier::p360(), QualityTier::p720(), QualityTier::p1080()], static fn (QualityTier $tier): bool => $tier->height <= (int) $sourceHeight);
        if ($tiers === []) {
            $tiers = [QualityTier::p360()];
        }
        $jobIds = [];
        foreach ($tiers as $tier) {
            $envelope = $this->commandBus->dispatch(new CreateTranscodeSessionCommand(
                userId: Uuid::fromString($user->getId()),
                videoId: $videoId,
                qualityTier: $tier,
                audioProfile: AudioProfile::streamingStereo(),
                priority: SessionPriority::Normal,
                audioLanguages: ['en'],
                requestId: $requestId,
            ));
            $session = $envelope->last(HandledStamp::class)?->getResult();
            if (!$session instanceof TranscodeSession) {
                throw new \LogicException('Video admission did not return a playback session.');
            }
            $jobIds[] = $session->getJobId()->toString();
        }
        return $this->sessionsForJobs($videoId, $requestId, $jobIds, true);
    }
}
