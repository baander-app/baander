<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Auth;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\Port\PlaybackPortInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\ValueObject\SessionPriority;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AuthorizedPlayback implements PlaybackPortInterface
{
    public function __construct(
        private Security $security,
        private Connection $connection,
        private MessageBusInterface $commandBus,
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

    public function start(Uuid $videoId): void
    {
        $this->assertAccess($videoId);
        $user = $this->security->getUser();
        if ($user === null || !method_exists($user, 'getId')) {
            throw new AccessDeniedException('Authentication required.');
        }
        foreach ([QualityTier::p360(), QualityTier::p720(), QualityTier::p1080()] as $tier) {
            $this->commandBus->dispatch(new CreateTranscodeSessionCommand(
                userId: Uuid::fromString($user->getId()),
                videoId: $videoId,
                qualityTier: $tier,
                audioProfile: AudioProfile::streamingStereo(),
                priority: SessionPriority::Normal,
                audioLanguages: ['en'],
            ));
        }
    }
}
