<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces the primary and featured artist credits of one song or album, writing only the credits
 * that differ, so a match that leaves most credits as they were writes few rows. Credits of every
 * other role stay.
 *
 * Deletes and renames run as statements at once, before the unit of work inserts the new credits
 * on its next flush, so a credit deleted here never collides with an inserted one on the unique
 * artist, item and role. The credit entities this manager holds for rows the statements changed
 * leave the unit of work; nothing refers to a credit entity.
 *
 * @property EntityManagerInterface $entityManager
 */
trait PerformerCreditsTrait
{
    /**
     * @param class-string<ArtistSongEntity|ArtistAlbumEntity>                    $link
     * @param 'song'|'album'                                                      $item
     * @param list<array{artistId: Uuid, role: ArtistRole, creditedName: string}> $credits
     *
     * @return bool whether a credit changed
     */
    private function writePerformerCredits(string $link, string $item, Uuid $itemId, array $credits): bool
    {
        $wanted = [];
        foreach ($credits as $credit) {
            $wanted[self::performerCreditKey($credit['artistId']->toString(), $credit['role']->value)] ??= $credit;
        }

        $changed = [];
        $stale = [];
        foreach ($this->currentPerformerCredits($link, $item, $itemId) as $current) {
            $key = self::performerCreditKey($current['artistId'], $current['role']);
            $credit = $wanted[$key] ?? null;
            unset($wanted[$key]);
            if ($credit === null) {
                $stale[] = $current['id'];
            } elseif ($credit['creditedName'] !== $current['creditedName']) {
                $this->entityManager->createQuery("UPDATE {$link} credit SET credit.creditedName = :name WHERE credit.id = :id")
                    ->setParameter('name', $credit['creditedName'])
                    ->setParameter('id', $current['id'])
                    ->execute();
                $changed[] = $current['id'];
            }
        }
        if ($stale !== []) {
            $this->entityManager->createQuery("DELETE {$link} credit WHERE credit.id IN (:ids)")
                ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toString(), $stale))
                ->execute();
        }
        $this->releaseCreditEntities($link, [...$changed, ...$stale]);

        $itemEntity = $wanted === [] ? null : $this->creditedItem($item, $itemId);
        foreach ($wanted as $credit) {
            $artist = $this->entityManager->find(ArtistEntity::class, $credit['artistId'])
                ?? throw new \RuntimeException(sprintf('Artist not found: %s', $credit['artistId']->toString()));
            $this->entityManager->persist(new $link($artist, $itemEntity, $credit['role']->value, $credit['creditedName']));
        }

        return $changed !== [] || $stale !== [] || $wanted !== [];
    }

    /**
     * @param class-string<ArtistSongEntity|ArtistAlbumEntity> $link
     * @param 'song'|'album'                                   $item
     *
     * @return list<array{id: Uuid, artistId: string, role: string, creditedName: string}>
     */
    private function currentPerformerCredits(string $link, string $item, Uuid $itemId): array
    {
        /** @var list<array{id: Uuid, artistId: Uuid|string, role: string, creditedName: string}> $rows */
        $rows = $this->entityManager
            ->createQuery(
                "SELECT credit.id AS id, IDENTITY(credit.artist) AS artistId, credit.role AS role, credit.creditedName AS creditedName FROM {$link} credit"
                . " WHERE credit.{$item} = :item AND credit.role IN (:roles)",
            )
            ->setParameter('item', $itemId)
            ->setParameter('roles', [ArtistRole::Primary->value, ArtistRole::Featured->value])
            ->getResult();

        return array_map(
            static fn (array $row): array => [...$row, 'artistId' => $row['artistId'] instanceof Uuid ? $row['artistId']->toString() : (string) $row['artistId']],
            $rows,
        );
    }

    /** @param 'song'|'album' $item */
    private function creditedItem(string $item, Uuid $itemId): SongEntity|AlbumEntity
    {
        $class = $item === 'song' ? SongEntity::class : AlbumEntity::class;

        return $this->entityManager->find($class, $itemId)
            ?? throw new \RuntimeException(sprintf('%s not found: %s', ucfirst($item), $itemId->toString()));
    }

    /**
     * @param class-string<ArtistSongEntity|ArtistAlbumEntity> $link
     * @param list<Uuid>                                       $ids
     */
    private function releaseCreditEntities(string $link, array $ids): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($ids as $id) {
            // The identity map keys an entity by its identifier values, here the ID's string form.
            $credit = $unitOfWork->tryGetById(['id' => $id], $link);
            if ($credit !== false) {
                $this->entityManager->detach($credit);
            }
        }
    }

    private static function performerCreditKey(string $artistId, string $role): string
    {
        return strtolower($artistId) . ' ' . $role;
    }
}
