<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumMatchStateEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AlbumMatchStateRepository implements AlbumMatchStateRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function findByAlbum(Uuid $albumId): ?AlbumMatchState
    {
        $entity = $this->entity($albumId);

        return $entity === null ? null : $this->toDomain($entity);
    }

    #[\Override]
    public function save(AlbumMatchState $state): void
    {
        $entity = $this->entity($state->albumId) ?? new AlbumMatchStateEntity(
            $this->entityManager->getReference(AlbumEntity::class, $state->albumId),
            $state->status->value,
            $state->source->value,
            $state->dueAt,
        );
        $this->syncToEntity($state, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    #[\Override]
    public function removeByAlbum(Uuid $albumId): void
    {
        $entity = $this->entity($albumId);
        if ($entity === null) {
            return;
        }
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    private function entity(Uuid $albumId): ?AlbumMatchStateEntity
    {
        return $this->entityManager->find(AlbumMatchStateEntity::class, $albumId);
    }

    private function toDomain(AlbumMatchStateEntity $entity): AlbumMatchState
    {
        return new AlbumMatchState(
            albumId: $entity->getAlbum()->getId(),
            status: AlbumMatchStatus::from($entity->getState()),
            source: AlbumMatchSource::from($entity->getSource()),
            dueAt: $entity->getDueAt(),
            reason: $entity->getReason() === null ? null : AlbumMatchReviewReason::from($entity->getReason()),
            checkedSongCount: $entity->getCheckedSongCount(),
            checkedAt: $entity->getCheckedAt(),
            bestScore: $entity->getBestScore(),
        );
    }

    private function syncToEntity(AlbumMatchState $state, AlbumMatchStateEntity $entity): void
    {
        $entity->setState($state->status->value);
        $entity->setSource($state->source->value);
        $entity->setDueAt($state->dueAt);
        $entity->setReason($state->reason?->value);
        $entity->setCheckedSongCount($state->checkedSongCount);
        $entity->setCheckedAt($state->checkedAt);
        $entity->setBestScore($state->bestScore);
    }
}
