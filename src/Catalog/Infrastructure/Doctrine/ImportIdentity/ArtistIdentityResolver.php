<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\ImportIdentity;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Port\ArtistIdentityResolverInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Resolves an import batch's artists under one advisory lock per tagged name and per ID. A unique
 * index cannot do this: a name without an ID and the same name with one would land on different
 * indexes, and a unique name would forbid the same-name artists people create by hand.
 */
final readonly class ArtistIdentityResolver implements ArtistIdentityResolverInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(array $identities): array
    {
        $distinct = [];
        foreach ($identities as $identity) {
            $distinct[$identity->key()] ??= $identity;
        }
        if ($distinct === []) {
            return [];
        }

        ImportIdentityLock::acquire($this->entityManager->getConnection(), self::lockKeys($distinct));
        $stored = $this->storedArtists($distinct);
        $index = new ArtistIdentityIndex(array_map(self::candidate(...), $stored));

        $artistIds = [];
        foreach ($distinct as $key => $identity) {
            $artistIds[$key] = $index->resolve($identity)->id;
        }
        $this->write($index->artists(), $stored);

        return $artistIds;
    }

    /**
     * The tagged name of every identity, and the ID of each that has one.
     *
     * @param array<string, ArtistIdentity> $identities
     *
     * @return list<string>
     */
    private static function lockKeys(array $identities): array
    {
        $keys = [];
        foreach ($identities as $identity) {
            $keys[] = ImportIdentityLock::artistNameKey($identity->name);
            if ($identity->musicbrainzId !== null) {
                $keys[] = ImportIdentityLock::artistMusicbrainzIdKey($identity->musicbrainzId);
            }
        }

        return $keys;
    }

    /**
     * Reads, after the locks were granted, every artist the identities may name: those with a
     * tagged name and those holding one of their IDs. As a statement of its own it reads, under
     * READ COMMITTED, what a transaction that held a lock first committed. The refresh replaces
     * what this unit of work still holds from an earlier batch, such as an artist that another
     * import has given an ID since.
     *
     * @param array<string, ArtistIdentity> $identities
     *
     * @return list<ArtistEntity>
     */
    private function storedArtists(array $identities): array
    {
        $names = array_map(static fn (ArtistIdentity $identity): string => $identity->name, $identities)
            |> array_unique(...)
            |> array_values(...);
        $mbids = array_map(static fn (ArtistIdentity $identity): ?string => $identity->musicbrainzId?->toString(), $identities)
            |> array_filter(...)
            |> array_unique(...)
            |> array_values(...);

        $query = $this->entityManager->createQueryBuilder()
            ->select('artist')
            ->from(ArtistEntity::class, 'artist')
            ->where('artist.name IN (:names)')
            ->setParameter('names', $names)
            ->orderBy('artist.createdAt', 'ASC')
            ->addOrderBy('artist.id', 'ASC');
        if ($mbids !== []) {
            $query->orWhere('artist.mbid IN (:mbids)')->setParameter('mbids', $mbids);
        }

        /** @var list<ArtistEntity> $artists */
        $artists = $query->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();

        return $artists;
    }

    private static function candidate(ArtistEntity $artist): ArtistIdentityCandidate
    {
        return new ArtistIdentityCandidate($artist->getId(), $artist->getName(), $artist->getMbid(), $artist->getCreatedAt());
    }

    /**
     * Persists the artists the index created and the IDs stored artists took on, then flushes once.
     *
     * @param list<ArtistIdentityCandidate> $resolved
     * @param list<ArtistEntity>            $stored
     */
    private function write(array $resolved, array $stored): void
    {
        $entities = [];
        foreach ($stored as $entity) {
            $entities[$entity->getId()->toString()] = $entity;
        }

        $changed = false;
        foreach ($resolved as $artist) {
            $entity = $entities[$artist->id->toString()] ?? null;
            if ($entity === null) {
                $entity = new ArtistEntity(new PublicId(), $artist->name, $artist->id);
                $entity->setMbid($artist->mbid);
                $this->entityManager->persist($entity);
                $changed = true;
            } elseif ($entity->getMbid() !== $artist->mbid) {
                $entity->setMbid($artist->mbid);
                $changed = true;
            }
        }

        if ($changed) {
            $this->entityManager->flush();
        }
    }
}
