<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Activity\Application\Port\ArtistActivityMergePortInterface;
use App\Catalog\Application\Port\ArtistMergePortInterface;
use App\Catalog\Application\Port\ArtistRemoval;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Favorites\Application\Port\ArtistFavoriteMergePortInterface;
use App\Recommendation\Application\Port\ArtistRecommendationMergePortInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

/**
 * Merges and removes artists through DQL statements on the caller's connection and transaction,
 * and keeps the unit of work in step with what they changed: the database deletes a removed
 * artist's credits with it and clears its reference from images and play history, so the entities
 * this manager holds for those rows are released or read again before anything flushes.
 */
final readonly class ArtistMergeService implements ArtistMergePortInterface
{
    /** Each credit entity, by the association naming the song or album it credits. */
    private const array CREDITS = [ArtistSongEntity::class => 'song', ArtistAlbumEntity::class => 'album'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArtistFavoriteMergePortInterface $favorites,
        private ArtistActivityMergePortInterface $activities,
        private ArtistRecommendationMergePortInterface $recommendations,
    ) {
    }

    #[\Override]
    public function lock(array $artistIds): array
    {
        $this->assertTransaction();
        $locked = $this->lockedArtists($artistIds) |> array_keys(...);
        if ($locked === []) {
            return [];
        }

        // Statements of their own after the lock, so under READ COMMITTED they see the credits
        // committed while it waited; a credit inserted after it waits for this transaction.
        $credited = [
            ...$this->creditedArtists(ArtistSongEntity::class, $locked),
            ...$this->creditedArtists(ArtistAlbumEntity::class, $locked),
        ];

        return array_combine($locked, array_map(static fn (string $id): bool => in_array($id, $credited, true), $locked));
    }

    #[\Override]
    #[\NoDiscard]
    public function merge(Uuid $sourceId, Uuid $targetId): ArtistRemoval
    {
        $this->assertTransaction();
        if ($sourceId->equals($targetId)) {
            throw new \LogicException('An artist cannot be merged into itself.');
        }
        // The statements below act on the database, so the unit of work's pending changes go first.
        $this->entityManager->flush();
        $artists = $this->lockedArtists([$sourceId, $targetId]);
        $source = $artists[$sourceId->toString()] ?? throw self::notFound($sourceId);
        $target = $artists[$targetId->toString()] ?? throw self::notFound($targetId);

        foreach (self::CREDITS as $link => $item) {
            $this->moveCredits($link, $item, $sourceId, $targetId);
        }
        $this->favorites->merge($source['publicId'], $target['publicId']);
        $this->activities->merge($sourceId, $targetId);
        $this->recommendations->merge($sourceId, $targetId);
        $coverTaken = $source['coverId'] !== null && $target['coverId'] === null && $this->giveCover($targetId, $source['coverId']);

        $this->deleteArtist($sourceId, $targetId);
        if ($coverTaken) {
            $this->reread($targetId);
        }

        return new ArtistRemoval($source['coverId'] === null || $coverTaken ? [] : [$source['coverId']]);
    }

    #[\Override]
    #[\NoDiscard]
    public function remove(Uuid $artistId): ArtistRemoval
    {
        $this->assertTransaction();
        $this->entityManager->flush();
        $artist = $this->lockedArtists([$artistId])[$artistId->toString()] ?? throw self::notFound($artistId);

        $this->favorites->remove($artist['publicId']);
        $this->recommendations->remove($artistId);
        $this->deleteArtist($artistId, null);

        return new ArtistRemoval($artist['coverId'] === null ? [] : [$artist['coverId']]);
    }

    private function assertTransaction(): void
    {
        // Outside a transaction each statement would commit on its own, and the row locks would end with it.
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Merge, remove or lock artists inside the transaction that holds their locks.');
        }
    }

    /**
     * Locks the rows FOR UPDATE in ID order and reads them; a row the caller already locked is
     * locked again without waiting.
     *
     * @param list<Uuid> $artistIds
     *
     * @return array<string, array{publicId: PublicId, coverId: ?Uuid}> by ID, in ID order
     */
    private function lockedArtists(array $artistIds): array
    {
        if ($artistIds === []) {
            return [];
        }

        /** @var list<array{id: Uuid|string, publicId: PublicId|string, coverId: Uuid|string|null}> $rows */
        $rows = $this->entityManager
            ->createQuery(
                'SELECT artist.id AS id, artist.publicId AS publicId, IDENTITY(artist.coverImage) AS coverId FROM '
                . ArtistEntity::class . ' artist WHERE artist.id IN (:ids) ORDER BY artist.id',
            )
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toString(), $artistIds))
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        $artists = [];
        foreach ($rows as $row) {
            $artists[(string) $row['id']] = [
                'publicId' => $row['publicId'] instanceof PublicId ? $row['publicId'] : PublicId::fromString($row['publicId']),
                'coverId' => $row['coverId'] === null ? null : Uuid::fromString((string) $row['coverId']),
            ];
        }

        return $artists;
    }

    /**
     * @param class-string<ArtistSongEntity|ArtistAlbumEntity> $link
     * @param list<string>                                     $artistIds
     *
     * @return list<string> the artists among them that the link credits
     */
    private function creditedArtists(string $link, array $artistIds): array
    {
        return $this->entityManager
            ->createQuery('SELECT DISTINCT IDENTITY(credit.artist) FROM ' . $link . ' credit WHERE credit.artist IN (:ids)')
            ->setParameter('ids', $artistIds)
            ->getSingleColumnResult()
            |> (static fn (array $ids): array => array_map(strval(...), $ids));
    }

    /**
     * Moves the source's credits of one kind to the target. Credits the target already holds for
     * the same song or album and role are dropped. Where one artist is primary and the other
     * featured on a song or album, the featured credit is dropped, so the target ends primary
     * under the primary credit's name, the artist text the song or album shows.
     *
     * @param class-string<ArtistSongEntity|ArtistAlbumEntity> $link
     * @param 'song'|'album'                                   $item
     */
    private function moveCredits(string $link, string $item, Uuid $sourceId, Uuid $targetId): void
    {
        $this->execute(
            "DELETE {$link} credit WHERE credit.artist = :source AND EXISTS (SELECT kept.id FROM {$link} kept"
            . " WHERE kept.artist = :target AND kept.{$item} = credit.{$item}"
            . ' AND (kept.role = credit.role OR (kept.role IS NULL AND credit.role IS NULL)))',
            ['source' => $sourceId, 'target' => $targetId],
        );
        $this->execute(
            "DELETE {$link} credit WHERE credit.artist IN (:pair) AND credit.role = :featured AND EXISTS (SELECT kept.id FROM {$link} kept"
            . " WHERE kept.artist IN (:pair) AND kept.artist <> credit.artist AND kept.{$item} = credit.{$item} AND kept.role = :primary)",
            [
                'pair' => [$sourceId->toString(), $targetId->toString()],
                'featured' => ArtistRole::Featured->value,
                'primary' => ArtistRole::Primary->value,
            ],
        );
        $this->execute(
            "UPDATE {$link} credit SET credit.artist = :target WHERE credit.artist = :source",
            ['source' => $sourceId, 'target' => $targetId],
        );
    }

    /** Gives the target the cover unless it took one since the read; the target's row is locked, so it has not. */
    private function giveCover(Uuid $targetId, Uuid $coverId): bool
    {
        return $this->execute(
            'UPDATE ' . ArtistEntity::class . ' artist SET artist.coverImage = :cover, artist.updatedAt = CURRENT_TIMESTAMP()'
            . ' WHERE artist.id = :target AND artist.coverImage IS NULL',
            ['cover' => $coverId, 'target' => $targetId],
        ) === 1;
    }

    /**
     * Deletes the artist's row, last. The credits this manager holds for it or for the surviving
     * artist no longer match their rows, and the database deletes the artist's credits with it, so
     * they leave the unit of work; nothing refers to a credit entity. Another entity holding the
     * artist is released when the database deletes it too, and otherwise read again after the
     * delete, which cleared or moved its reference. The artist leaves the unit of work only once
     * nothing managed holds it, or the next flush that writes anything would refuse it as new.
     */
    private function deleteArtist(Uuid $artistId, ?Uuid $survivorId): void
    {
        [$artistId, $survivorId] |> array_filter(...) |> $this->detachCredits(...);
        $artist = $this->managedArtist($artistId);
        $stale = [];
        if ($artist !== null) {
            foreach ($this->holdersOf($artist) as [$holder, $deletedWithArtist]) {
                if ($deletedWithArtist) {
                    $this->entityManager->detach($holder);
                } else {
                    $stale[] = $holder;
                }
            }
            $this->entityManager->detach($artist);
        }

        $deleted = $this->execute('DELETE ' . ArtistEntity::class . ' artist WHERE artist.id = :id', ['id' => $artistId]);
        if ($deleted !== 1) {
            throw new \LogicException('Artist "' . $artistId->toString() . '" was deleted while its row was locked.');
        }

        foreach ($stale as $holder) {
            $this->entityManager->refresh($holder);
        }
    }

    /** @param list<Uuid> $artistIds */
    private function detachCredits(array $artistIds): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach (self::CREDITS as $link => $item) {
            foreach ($unitOfWork->getIdentityMap()[$link] ?? [] as $credit) {
                if ($credit instanceof $link && !$unitOfWork->isUninitializedObject($credit) && self::creditsAny($credit, $artistIds)) {
                    $this->entityManager->detach($credit);
                }
            }
        }
    }

    /** @param list<Uuid> $artistIds */
    private static function creditsAny(ArtistSongEntity|ArtistAlbumEntity $credit, array $artistIds): bool
    {
        // Reading the ID does not load a lazy artist.
        return array_any($artistIds, $credit->getArtist()->getId()->equals(...));
    }

    private function managedArtist(Uuid $artistId): ?ArtistEntity
    {
        $artist = array_find(
            $this->entityManager->getUnitOfWork()->getIdentityMap()[ArtistEntity::class] ?? [],
            static fn (object $entity): bool => $entity instanceof ArtistEntity && $entity->getId()->equals($artistId),
        );

        return $artist instanceof ArtistEntity ? $artist : null;
    }

    /**
     * Every loaded entity the unit of work manages that holds the artist in a to-one association,
     * with whether the database deletes it with the artist. An entity still to be loaded reads the
     * row as the statements left it.
     *
     * @return list<array{object, bool}>
     */
    private function holdersOf(ArtistEntity $artist): array
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $holders = [];
        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            $metadata = $this->entityManager->getClassMetadata($class);
            $associations = array_filter(
                $metadata->associationMappings,
                static fn (AssociationMapping $association): bool => $association instanceof ToOneOwningSideMapping && $artist instanceof $association->targetEntity,
            );
            if ($associations === []) {
                continue;
            }
            foreach ($entities as $entity) {
                if ($unitOfWork->isUninitializedObject($entity)) {
                    continue;
                }
                $holding = array_find(
                    $associations,
                    static fn (AssociationMapping $association): bool => $metadata->getFieldValue($entity, $association->fieldName) === $artist,
                );
                if ($holding instanceof ToOneOwningSideMapping) {
                    $holders[] = [$entity, self::deletedWithTarget($holding)];
                }
            }
        }

        return $holders;
    }

    private static function deletedWithTarget(ToOneOwningSideMapping $association): bool
    {
        return array_any(
            $association->joinColumns,
            static fn (JoinColumnMapping $column): bool => strtolower($column->onDelete ?? '') === 'cascade',
        );
    }

    /** Reads the artist again when this manager holds it loaded, after a statement changed its row. */
    private function reread(Uuid $artistId): void
    {
        $artist = $this->managedArtist($artistId);
        if ($artist !== null && !$this->entityManager->getUnitOfWork()->isUninitializedObject($artist)) {
            $this->entityManager->refresh($artist);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function execute(string $dql, array $parameters): int
    {
        $query = $this->entityManager->createQuery($dql);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        return (int) $query->execute();
    }

    private static function notFound(Uuid $artistId): NotFoundException
    {
        return new NotFoundException('Artist "' . $artistId->toString() . '" not found.');
    }
}
