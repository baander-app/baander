<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Query;

use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Query\MusicBrainzMatch\CatalogCredit;
use App\Catalog\Application\Query\MusicBrainzMatch\MatchCatalogReadInterface;
use App\Catalog\Application\Query\MusicBrainzMatch\PredictedArtist;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistIdentityCandidate;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ArtistIdentityIndex;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The catalog reads of a MusicBrainz comparison, as plain DQL selects of committed rows. Nothing is
 * locked or written: an apply takes its locks and reads again before it writes.
 */
final readonly class MatchCatalogReadQuery implements MatchCatalogReadInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[\Override]
    public function songCredits(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }

        $rows = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT s.id AS ownerId, a.id AS artistId, a.publicId AS artistPublicId, a.name AS artistName,
                       c.role AS role, c.creditedName AS creditedName,
                       CASE WHEN c.role = 'primary' THEN 0 ELSE 1 END AS HIDDEN primaryFirst
                FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity c
                JOIN c.artist a
                JOIN c.song s
                WHERE s.id IN (:songIds)
                ORDER BY primaryFirst ASC, c.creditedName ASC, a.id ASC
                DQL,
        )
            ->setParameter('songIds', $songIds)
            ->getResult();

        $credits = [];
        foreach ($rows as $row) {
            $credits[$row['ownerId']->toString()][] = self::credit($row);
        }

        return $credits;
    }

    #[\Override]
    public function albumCredits(Uuid $albumId): array
    {
        $rows = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT a.id AS artistId, a.publicId AS artistPublicId, a.name AS artistName,
                       c.role AS role, c.creditedName AS creditedName,
                       CASE WHEN c.role = 'primary' THEN 0 ELSE 1 END AS HIDDEN primaryFirst
                FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity c
                JOIN c.artist a
                JOIN c.album al
                WHERE al.id = :albumId
                ORDER BY primaryFirst ASC, c.creditedName ASC, a.id ASC
                DQL,
        )
            ->setParameter('albumId', $albumId)
            ->getResult();

        return array_map(self::credit(...), $rows);
    }

    #[\Override]
    public function albumGenres(Uuid $albumId): array
    {
        $rows = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT g.name AS name
                FROM App\Catalog\Infrastructure\Doctrine\Entity\GenreAlbumEntity ga
                JOIN ga.genre g
                JOIN ga.album al
                WHERE al.id = :albumId
                ORDER BY g.name ASC
                DQL,
        )
            ->setParameter('albumId', $albumId)
            ->getResult();

        return array_column($rows, 'name');
    }

    /**
     * Reads the artists the identities may name, as ArtistIdentityResolver reads them, and lets the
     * same ArtistIdentityIndex decide in memory, without its locks or its writes.
     */
    #[\Override]
    public function predictArtists(array $identities): array
    {
        if ($identities === []) {
            return [];
        }

        $stored = $this->storedArtists($identities);
        $index = new ArtistIdentityIndex(array_map(
            static fn (array $artist): ArtistIdentityCandidate => new ArtistIdentityCandidate($artist['id'], $artist['name'], $artist['mbid'], $artist['createdAt']),
            $stored,
        ));
        $byId = array_column($stored, null, 'key');

        $predicted = [];
        foreach ($identities as $identity) {
            $candidate = $index->resolve($identity);
            $artist = $byId[$candidate->id->toString()] ?? null;
            $predicted[$identity->key()] = $artist === null
                ? PredictedArtist::created($candidate->name)
                : new PredictedArtist($artist['id'], $artist['publicId']->toString(), $artist['name'], $artist['mbid'] === null && $candidate->mbid !== null);
        }

        return $predicted;
    }

    #[\Override]
    public function artistsCreditedElsewhere(array $artistIds, array $songIds, array $albumIds): array
    {
        if ($artistIds === []) {
            return [];
        }

        $onSongs = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT a.id AS artistId')
            ->from('App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity', 'c')
            ->join('c.artist', 'a')
            ->join('c.song', 's')
            ->where('a.id IN (:artistIds)')
            ->setParameter('artistIds', $artistIds);
        if ($songIds !== []) {
            $onSongs->andWhere('s.id NOT IN (:songIds)')->setParameter('songIds', $songIds);
        }
        $onAlbums = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT a.id AS artistId')
            ->from('App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity', 'c')
            ->join('c.artist', 'a')
            ->join('c.album', 'al')
            ->where('a.id IN (:artistIds)')
            ->setParameter('artistIds', $artistIds);
        if ($albumIds !== []) {
            $onAlbums->andWhere('al.id NOT IN (:albumIds)')->setParameter('albumIds', $albumIds);
        }

        return [...$onSongs->getQuery()->getResult(), ...$onAlbums->getQuery()->getResult()]
            |> (static fn (array $rows): array => array_map(static fn (array $row): string => $row['artistId']->toString(), $rows))
            |> array_unique(...)
            |> array_values(...);
    }

    /**
     * Every artist with an identity's name or MusicBrainz ID, oldest first, as committed now. Plain
     * rows rather than entities: a unit of work that already holds one of these artists, such as an
     * apply's, would otherwise hand back its own copy instead of what is committed.
     *
     * @param list<ArtistIdentity> $identities
     *
     * @return list<array{key: string, id: Uuid, publicId: PublicId, name: string, mbid: string|null, createdAt: DateTimeImmutable}>
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
            ->select('artist.id AS id', 'artist.publicId AS publicId', 'artist.name AS name', 'artist.mbid AS mbid', 'artist.createdAt AS createdAt')
            ->from(ArtistEntity::class, 'artist')
            ->where('artist.name IN (:names)')
            ->setParameter('names', $names)
            ->orderBy('artist.createdAt', 'ASC')
            ->addOrderBy('artist.id', 'ASC');
        if ($mbids !== []) {
            $query->orWhere('artist.mbid IN (:mbids)')->setParameter('mbids', $mbids);
        }

        /** @var list<array{id: Uuid, publicId: PublicId, name: string, mbid: string|null, createdAt: DateTimeImmutable}> $rows */
        $rows = $query->getQuery()->getResult();

        return array_map(static fn (array $row): array => ['key' => $row['id']->toString(), ...$row], $rows);
    }

    /** @param array{artistId: Uuid, artistPublicId: PublicId, artistName: string, role: string|null, creditedName: string} $row */
    private static function credit(array $row): CatalogCredit
    {
        return new CatalogCredit($row['artistId'], $row['artistPublicId']->toString(), $row['artistName'], $row['role'], $row['creditedName']);
    }
}
