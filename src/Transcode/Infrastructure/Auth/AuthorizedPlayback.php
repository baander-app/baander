<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Auth;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Application\DTO\DeploymentProfile;
use App\QoL\Application\Port\AllowedQualityTiersPortInterface;
use App\Shared\Application\Exception\HandlerFailure;
use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\DTO\PlaybackEndReason;
use App\Transcode\Application\Exception\TranscodeSessionLimitException;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Application\Service\TranscodeSessionShare;
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
        private AllowedQualityTiersPortInterface $qualityTiers,
        private TranscodeSessionShare $share,
        private DeploymentProfile $deploymentProfile = new DeploymentProfile(),
    ) {}

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
                . ' JOIN movies m ON m.id = mv.movie_id'
                . ' JOIN user_library_access a ON a.library_id = m.library_id'
                . ' WHERE mv.video_id = v.id AND a.user_id = :userId)';
            $params['userId'] = $user->getId();
        }
        if ($this->connection->fetchOne($sql, $params) === false) {
            throw new AccessDeniedException('Video is not accessible.');
        }
    }

    public function findOwnedTranscodeJobVideoId(Uuid $jobId, Uuid $ownerUserId): ?Uuid
    {
        $videoId = $this->connection->fetchOne(
            'SELECT video_id FROM transcode_jobs WHERE id = :jobId AND owner_user_id = :owner',
            ['jobId' => $jobId->toString(), 'owner' => $ownerUserId->toString()],
        );

        return is_string($videoId) ? Uuid::fromString($videoId) : null;
    }

    public function findSessions(Uuid $videoId, string $requestId, array $jobIds): array
    {
        return $this->sessionsForJobs(
            $videoId,
            $requestId,
            array_map(
                static fn(PublicId $id): string => $id->toString(),
                $jobIds,
            ),
            false,
        );
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
            . ' JOIN transcode_jobs j ON j.id = s.job_id'
            . ' JOIN transcode_runtime_state r ON r.session_id = s.id AND r.job_id = j.id'
            . ' WHERE s.user_id = :user AND j.owner_user_id = :user AND s.video_id = :video AND j.video_id = :video'
            . ' AND j.request_id = :request AND r.request_id = :request'
            . $selection
            // An ended session is not held, also between its expiry and the removal of its job.
            . " AND j.status IN ('pending', 'in_progress', 'completed') AND s.state NOT IN ('cancelled', 'failed')"
            . ' ORDER BY j.height, j.public_id',
            ['user' => $user->getId(), 'video' => $videoId->toString(), 'request' => $requestId, 'jobs' => $jobIds],
            ['jobs' => ArrayParameterType::STRING],
        );
        $sessions = [];
        foreach ($rows as $row) {
            $sessions[$row['job_id']] = PublicId::fromString($row['session_id']);
        }
        return $sessions;
    }

    public function endReason(Uuid $videoId, string $requestId, array $jobIds): PlaybackEndReason
    {
        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw new AccessDeniedException('Authentication required.');
        }
        $rows = $jobIds === [] ? [] : $this->connection->fetchAllAssociative(
            'SELECT j.status AS job_status, r.outcome, s.state FROM transcode_jobs j'
            . ' LEFT JOIN transcode_runtime_state r ON r.job_id = j.id AND r.request_id = :request'
            . ' LEFT JOIN transcode_sessions s ON s.id = r.session_id AND s.user_id = :user'
            . ' WHERE j.public_id IN (:jobs) AND j.owner_user_id = :user AND j.video_id = :video AND j.request_id = :request',
            ['user' => $user->getId(), 'video' => $videoId->toString(), 'request' => $requestId,
                'jobs' => array_map(static fn(PublicId $id): string => $id->toString(), $jobIds)],
            ['jobs' => ArrayParameterType::STRING],
        );
        $failed = static fn(array $row): bool => $row['job_status'] === 'failed' || $row['state'] === 'failed'
            || in_array($row['outcome'], ['failed', 'refused'], true);
        // Expiry and a start that needs room release a job without cancelling it, and leave a finished
        // execution complete when they end its session. A cancelled job, or a cancelled session whose
        // finished execution was cancelled with it, was cancelled on purpose.
        $cancelled = static fn(array $row): bool => $row['job_status'] === 'cancelled'
            || ($row['job_status'] === 'completed' && $row['state'] === 'cancelled' && $row['outcome'] === 'cancelled');
        return match (true) {
            array_any($rows, $failed) => PlaybackEndReason::Failed,
            array_any($rows, $cancelled) => PlaybackEndReason::Cancelled,
            // Jobs that are gone, or released and waiting for cleanup.
            default => PlaybackEndReason::Expired,
        };
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
            . ' WHERE public_id IN (:sessions) AND user_id = :user AND updated_at < :aged',
            [
                'now' => $now,
                'aged' => $now->modify(sprintf('-%d seconds', self::SIGN_OF_LIFE_INTERVAL_SECONDS)),
                'sessions' => array_map(static fn(PublicId $id): string => $id->toString(), $sessionIds),
                'user' => $user->getId(),
            ],
            [
                'now' => Types::DATETIME_IMMUTABLE,
                'aged' => Types::DATETIME_IMMUTABLE,
                'sessions' => ArrayParameterType::STRING,
            ],
        );
    }

    public function start(
        Uuid $videoId,
        string $requestId,
        ?int $audioStreamIndex = null,
        int $audioOffsetMs = 0,
        ?string $qualityTier = null,
    ): array {
        $this->assertAccess($videoId);
        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw new AccessDeniedException('Authentication required.');
        }
        $tiers = $qualityTier === null ? $this->automaticTiers($videoId) : [$this->manualTier($videoId, $qualityTier)];
        $userId = Uuid::fromString($user->getId());
        // Each tier is admitted in a transaction of its own, so the whole start must fit before the first.
        $held = $this->share->assertRoomFor($userId, $videoId, $requestId, array_map(static fn (QualityTier $tier): string => $tier->name, $tiers));
        $jobIds = [];
        $created = [];
        foreach ($tiers as $tier) {
            try {
                $envelope = $this->commandBus->dispatch(new CreateTranscodeSessionCommand(
                    userId: $userId,
                    videoId: $videoId,
                    qualityTier: $tier,
                    audioProfile: AudioProfile::streamingStereo(),
                    priority: SessionPriority::Normal,
                    audioLanguages: [],
                    requestId: $requestId,
                    audioStreamIndex: $audioStreamIndex,
                    audioOffsetMs: $audioOffsetMs,
                ));
            } catch (\Throwable $failure) {
                if (HandlerFailure::cause($failure) instanceof TranscodeSessionLimitException) {
                    // Another start of the user took the room after the check; the tiers this start created end too.
                    $this->share->withdraw($created);
                }
                throw $failure;
            }
            $session = $envelope->last(HandledStamp::class)?->getResult();
            if (!$session instanceof TranscodeSession) {
                throw new \LogicException('Video admission did not return a playback session.');
            }
            if (!array_any($held, static fn (Uuid $id): bool => $id->equals($session->getId()))) {
                $created[] = $session->getId();
            }
            $jobIds[] = $session->getJobId()->toString();
        }
        return $this->sessionsForJobs($videoId, $requestId, $jobIds, true);
    }

    /** @return list<QualityTier> */
    private function automaticTiers(Uuid $videoId): array
    {
        $sourceHeight = $this->connection->fetchOne(
            'SELECT height FROM videos WHERE id = :videoId',
            ['videoId' => $videoId->toString()],
        );
        if ($sourceHeight === false) {
            throw new TranscodeStartupUnavailableException();
        }
        $tiers = array_filter(
            [QualityTier::p360(), QualityTier::p720(), QualityTier::p1080()],
            static fn(QualityTier $tier): bool => $tier->height <= (int) $sourceHeight,
        ) |> array_values(...);
        if ($tiers === []) {
            $tiers = [QualityTier::p360()];
        }
        // Each tier is a session of the user's share, so a share below the tier count admits fewer
        // renditions rather than refusing every automatic start.
        $tierLimit = min($this->deploymentProfile->videoEncoderSlots, $this->share->limit());

        return array_slice($tiers, 0, max(1, $tierLimit));
    }

    private function manualTier(Uuid $videoId, string $qualityTier): QualityTier
    {
        $tier = QualityTier::fromString($qualityTier);
        if (!in_array($tier->name, $this->qualityTiers->qualifiedTierNames(), true)) {
            throw new TranscodeStartupUnavailableException();
        }
        $source = $this->connection->fetchAssociative(
            'SELECT width, height FROM videos WHERE id = :videoId',
            ['videoId' => $videoId->toString()],
        );
        if ($source === false || (int) $source['width'] < $tier->width || (int) $source['height'] < $tier->height) {
            throw new TranscodeStartupUnavailableException();
        }
        return $tier;
    }
}
