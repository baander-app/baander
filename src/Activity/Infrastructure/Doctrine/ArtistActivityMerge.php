<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine;

use App\Activity\Application\Port\ArtistActivityMergePortInterface;
use App\Activity\Infrastructure\Doctrine\Entity\MediaActivityEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The activities this manager holds keep the source artist until read again; Catalog, which
 * deletes that artist, reads every loaded entity that holds it again after the delete.
 */
final readonly class ArtistActivityMerge implements ArtistActivityMergePortInterface
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
            ->createQuery('UPDATE ' . MediaActivityEntity::class . ' activity SET activity.artist = :target WHERE activity.artist = :source')
            ->setParameter('source', $source)
            ->setParameter('target', $target)
            ->execute();
    }
}
