<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine;

use App\Activity\Application\Port\AlbumActivityMergePortInterface;
use App\Activity\Infrastructure\Doctrine\Entity\MediaActivityEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AlbumActivityMerge implements AlbumActivityMergePortInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function merge(Uuid $source, Uuid $target): void
    {
        // The activity's own updated time stays: nothing the listener did changed.
        $this->entityManager
            ->createQuery('UPDATE ' . MediaActivityEntity::class . ' activity SET activity.album = :target WHERE activity.album = :source')
            ->setParameter('source', $source)
            ->setParameter('target', $target)
            ->execute();
        $this->release($source);
    }

    /**
     * The activities this manager holds of the album still name it, which their rows no longer
     * do; nothing refers to an activity. One still to be loaded reads its row as the statement
     * left it.
     */
    private function release(Uuid $album): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap()[MediaActivityEntity::class] ?? [] as $activity) {
            // Reading the album's ID does not load a lazy album.
            if ($activity instanceof MediaActivityEntity
                && !$unitOfWork->isUninitializedObject($activity)
                && $activity->getAlbum()?->getId()->equals($album) === true) {
                $this->entityManager->detach($activity);
            }
        }
    }
}
