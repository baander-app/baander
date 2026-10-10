<?php

declare(strict_types=1);

namespace App\Lyrics\Infrastructure\Doctrine\Repository;

use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Model\LyricsState;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Lyrics\Infrastructure\Doctrine\Entity\LyricsEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
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

    /**
     * Inserts through DBAL with ON CONFLICT DO NOTHING rather than catching the unique
     * violation, which would close the entity manager for the rest of the request or message.
     */
    public function add(Lyrics $lyrics): bool
    {
        $inserted = $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO lyrics (id, song_id, plain_lyrics, synced_lyrics, source, source_url, lrclib_id, is_instrumental, created_at, updated_at)
             VALUES (:id, :song_id, :plain_lyrics, :synced_lyrics, :source, :source_url, :lrclib_id, :is_instrumental, :created_at, :updated_at)
             ON CONFLICT (song_id) DO NOTHING',
            [
                'id' => $lyrics->getId()->toString(),
                'song_id' => $lyrics->getSongId()->toString(),
                'plain_lyrics' => $lyrics->getLyrics(),
                'synced_lyrics' => $lyrics->getSyncedLyrics(),
                'source' => $lyrics->getSource(),
                'source_url' => $lyrics->getSourceUrl(),
                'lrclib_id' => $lyrics->getLrclibId(),
                'is_instrumental' => $lyrics->isInstrumental(),
                'created_at' => $lyrics->getCreatedAt(),
                'updated_at' => $lyrics->getUpdatedAt(),
            ],
            [
                'lrclib_id' => ParameterType::INTEGER,
                'is_instrumental' => ParameterType::BOOLEAN,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ],
        );

        return $inserted === 1;
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
