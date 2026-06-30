<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Doctrine\Repository;

use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Model\LyricsState;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Lyrics\Infrastructure\Doctrine\Entity\LyricsEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine repository for the Lyrics aggregate.
 */
final class LyricsRepository implements LyricsRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Lyrics $lyrics): void
    {
        $entity = $this->findEntityOrCreate($lyrics);
        $this->syncToEntity($lyrics, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findBySongId(Uuid $songId): ?Lyrics
    {
        $entity = $this->entityManager
            ->getRepository(LyricsEntity::class)
            ->findOneBy(['songId' => $songId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByLrclibId(int $id): ?Lyrics
    {
        $entity = $this->entityManager
            ->getRepository(LyricsEntity::class)
            ->findOneBy(['lrclibId' => $id]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function delete(Lyrics $lyrics): void
    {
        $entity = $this->entityManager
            ->getRepository(LyricsEntity::class)
            ->find($lyrics->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    // --- Internal ---

    private function toDomain(LyricsEntity $entity): Lyrics
    {
        return Lyrics::reconstitute(new LyricsState(
            id: $entity->getId(),
            songId: $entity->getSongId(),
            lyrics: $entity->getPlainLyrics() ?? '',
            syncedLyrics: $entity->getSyncedLyrics(),
            source: $entity->getSource(),
            sourceUrl: $entity->getSourceUrl(),
            lrclibId: $entity->getLrclibId(),
            isInstrumental: $entity->isInstrumental(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function findEntityOrCreate(Lyrics $lyrics): LyricsEntity
    {
        $existing = $this->entityManager
            ->getRepository(LyricsEntity::class)
            ->find($lyrics->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new LyricsEntity(
            id: $lyrics->getId(),
            songId: $lyrics->getSongId(),
            source: $lyrics->getSource(),
        );
    }

    private function syncToEntity(Lyrics $lyrics, LyricsEntity $entity): void
    {
        $entity->setPlainLyrics($lyrics->getLyrics());
        $entity->setSyncedLyrics($lyrics->getSyncedLyrics());
        $entity->setSource($lyrics->getSource());
        $entity->setSourceUrl($lyrics->getSourceUrl());
        $entity->setLrclibId($lyrics->getLrclibId());
        $entity->setInstrumental($lyrics->isInstrumental());
    }
}
