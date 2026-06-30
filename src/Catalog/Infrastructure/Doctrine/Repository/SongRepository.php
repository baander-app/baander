<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\SongState;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\CursorDirection;
use App\Shared\Domain\Model\CursorPage;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Repository\PgroongaSearchTrait;
use App\Shared\Infrastructure\Pagination\CursorCodec;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pure domain repository for songs.
 */
final class SongRepository implements SongRepositoryInterface
{
    use PgroongaSearchTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CursorPaginator $cursorPaginator,
        private readonly CursorCodec $cursorCodec,
    ) {
    }

    public function save(Song $song): void
    {
        $entity = $this->findEntityOrCreate($song);
        $this->syncToEntity($song, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(Song $song): void
    {
        $entity = $this->findEntityOrCreate($song);
        $this->syncToEntity($song, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Song
    {
        $entity = $this->entityManager
            ->getRepository(SongEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?Song
    {
        $entity = $this->entityManager
            ->getRepository(SongEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPath(string $path): ?Song
    {
        $entity = $this->entityManager
            ->getRepository(SongEntity::class)
            ->findOneBy(['path' => $path]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByHash(string $hash): ?Song
    {
        $entity = $this->entityManager
            ->getRepository(SongEntity::class)
            ->findOneBy(['hash' => $hash]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $entities = $this->entityManager
            ->getRepository(SongEntity::class)
            ->findBy(['id' => $uuids]);

        $result = [];
        foreach ($entities as $entity) {
            $song = $this->toDomain($entity);
            $result[$song->getId()->toString()] = $song;
        }

        return $result;
    }

    /**
     * @return Song[]
     */
    public function findByAlbum(Uuid $albumId, int $limit = 100): array
    {
        $entities = $this->entityManager
            ->getRepository(SongEntity::class)
            ->findBy(
                ['album' => $albumId],
                ['id' => 'ASC'],
                $limit,
            );

        return array_map(fn (SongEntity $entity) => $this->toDomain($entity), $entities);
    }

    /**
     * @return Song[]
     */
    public function findByAlbumSortedByTrack(Uuid $albumId): array
    {
        $entities = $this->entityManager
            ->getRepository(SongEntity::class)
            ->findBy(
                ['album' => $albumId],
                ['disc' => 'ASC', 'track' => 'ASC'],
            );

        return array_map(fn (SongEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function search(SearchOptions $options): SearchResult
    {
        if (!$options->hasQuery()) {
            return SearchResult::empty();
        }

        $result = $this->buildScoredQuery(
            $options,
            $this->entityManager,
            SongEntity::class,
            'songs',
            'title',
        );

        $songs = array_map(
            fn (SongEntity $entity) => self::entityToDomain($entity),
            $result['entities'],
        );

        return SearchResult::create($songs, $result['total'], $result['highestScore']);
    }

    public function searchWithCursor(SearchOptions $options): CursorPage
    {
        $qb = $this->entityManager
            ->getRepository(SongEntity::class)
            ->createQueryBuilder('s');

        $applyFilters = function (\Doctrine\ORM\QueryBuilder $qb, array $filters): void {
            foreach ($filters as $filter) {
                match ($filter['field']) {
                    'genres' => $this->applyGenreFilter($qb, $filter['operator'], $filter['value']),
                    'artistId' => $this->applyArtistIdFilter($qb, $filter['value']),
                    'albumId' => $this->applyAlbumIdFilter($qb, $filter['value']),
                    'publicIds' => $this->applyPublicIdsFilter($qb, $filter['value']),
                    default => null,
                };
            }
        };

        $qb = $this->buildFilterQuery($options, $qb, 's.title', $applyFilters);

        $sortField = $options->hasSort() ? $options->getSortField() : 'title';
        $sortColumn = 's.title';
        if ($options->hasSort()) {
            $sortColumn = $this->resolveSortColumn($options->getSortField(), $qb);
        }

        $valueExtractor = $this->buildValueExtractor($sortField);

        // The cursor paginator always sorts ASC internally. For DESC sort,
        // reverse the cursor direction and swap result cursors.
        $isDesc = $options->hasSort() && strtoupper($options->getSortOrder()) === 'DESC';

        if ($isDesc) {
            $effectiveCursor = null;
            if ($options->getCursor() !== null) {
                $cursor = $options->getCursor();
                $reversedDirection = ($cursor->getDirection() === CursorDirection::Next)
                    ? CursorDirection::Prev
                    : CursorDirection::Next;
                $effectiveCursor = Cursor::create($reversedDirection, $cursor->getValues());
            }

            $result = $this->cursorPaginator->paginate($qb, $sortColumn, 's.id', $effectiveCursor, $options->getLimit(), $valueExtractor);

            // Reverse items to present DESC order and swap cursors.
            $domainItems = array_map(fn(SongEntity $e) => self::entityToDomain($e), array_reverse($result->items));

            return new CursorPage(
                items: $domainItems,
                nextCursor: $result->prevCursor !== null ? $this->cursorCodec->encode($result->prevCursor) : null,
                prevCursor: $result->nextCursor !== null ? $this->cursorCodec->encode($result->nextCursor) : null,
                hasNextPage: $result->hasPreviousPage,
                hasPreviousPage: $result->hasNextPage,
                total: $result->total,
                staleCursor: $result->staleCursor,
                perPage: $result->perPage,
            );
        }

        $result = $this->cursorPaginator->paginate($qb, $sortColumn, 's.id', $options->getCursor(), $options->getLimit(), $valueExtractor);

        $domainItems = array_map(fn(SongEntity $e) => self::entityToDomain($e), $result->items);

        return new CursorPage(
            items: $domainItems,
            nextCursor: $result->nextCursor !== null ? $this->cursorCodec->encode($result->nextCursor) : null,
            prevCursor: $result->prevCursor !== null ? $this->cursorCodec->encode($result->prevCursor) : null,
            hasNextPage: $result->hasNextPage,
            hasPreviousPage: $result->hasPreviousPage,
            total: $result->total,
            staleCursor: $result->staleCursor,
            perPage: $result->perPage,
        );
    }

    public function count(): int
    {
        return (int) $this->entityManager
            ->getRepository(SongEntity::class)
            ->count([]);
    }

    public function countByAlbum(Uuid $albumId): int
    {
        return (int) $this->entityManager
            ->getRepository(SongEntity::class)
            ->count(['album' => $albumId]);
    }

    public function delete(Song $song): void
    {
        $entity = $this->entityManager
            ->getRepository(SongEntity::class)
            ->find($song->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    public function linkArtistToSong(Uuid $songId, string $artistName, string $role): void
    {
        $songEntity = $this->entityManager
            ->getRepository(SongEntity::class)
            ->find($songId);

        if ($songEntity === null) {
            return;
        }

        $artistEntity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['name' => $artistName]);

        if ($artistEntity === null) {
            return;
        }

        // Check for existing link to avoid unique constraint violation
        $existing = $this->entityManager
            ->getRepository(ArtistSongEntity::class)
            ->findOneBy([
                'artist' => $artistEntity->getId(),
                'song' => $songEntity->getId(),
                'role' => $role,
            ]);

        if ($existing !== null) {
            return;
        }

        $artistSong = new ArtistSongEntity($artistEntity, $songEntity, $role);
        $this->entityManager->persist($artistSong);
    }

    public function getArtistNameForSong(Uuid $songId): ?string
    {
        $result = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT a.name
                FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity ass
                JOIN ass.artist a
                JOIN ass.song s
                WHERE s.id = :songId AND ass.role = 'primary'
                DQL,
        )
            ->setParameter('songId', $songId)
            ->getOneOrNullResult();

        return $result['name'] ?? null;
    }

    public function getArtistNamesForSongs(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }

        $results = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT s.id AS songId, a.name AS artistName
                FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity ass
                JOIN ass.artist a
                JOIN ass.song s
                WHERE s.id IN (:songIds) AND ass.role = 'primary'
                DQL,
        )
            ->setParameter('songIds', $songIds)
            ->getResult();

        $map = [];
        foreach ($results as $row) {
            $map[$row['songId']->toString()] = $row['artistName'];
        }

        return $map;
    }

    public function getGenreNamesForSongs(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }

        $results = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT IDENTITY(gs.song) AS songId, g.name
                FROM App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity gs
                JOIN gs.genre g
                WHERE gs.song IN (:songIds)
                DQL,
        )
            ->setParameter('songIds', $songIds)
            ->getResult();

        $map = [];
        foreach ($results as $row) {
            $songId = $row['songId'];
            $map[$songId][] = $row['name'];
        }

        return $map;
    }

    public function getAlbumTitlesByIds(array $albumIds): array
    {
        if ($albumIds === []) {
            return [];
        }

        $results = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT a.id AS albumId, a.title
                FROM App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity a
                WHERE a.id IN (:albumIds)
                DQL,
        )
            ->setParameter('albumIds', $albumIds)
            ->getResult();

        $map = [];
        foreach ($results as $row) {
            $map[$row['albumId']->toString()] = $row['title'];
        }

        return $map;
    }

    public function findAllForRecommendations(): array
    {
        $entities = $this->entityManager
            ->getRepository(SongEntity::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.album', 'a')
            ->leftJoin('s.genres', 'gs')
            ->getQuery()
            ->getResult();

        return array_map(fn (SongEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findUpdatedAfter(\DateTimeImmutable $since): array
    {
        $entities = $this->entityManager
            ->getRepository(SongEntity::class)
            ->createQueryBuilder('s')
            ->where('s.updatedAt > :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        return array_map(fn (SongEntity $entity) => $this->toDomain($entity), $entities);
    }

    // --- Internal ---

    private function applyArtistIdFilter(\Doctrine\ORM\QueryBuilder $qb, string $artistPublicId): void
    {
        $artistEntity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['publicId' => PublicId::fromString($artistPublicId)]);

        if ($artistEntity === null) {
            $qb->andWhere('1 = 0');
            return;
        }

        $qb->andWhere($qb->expr()->in(
            's.id',
            'SELECT IDENTITY(ass_filter.song) FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity ass_filter ' .
            'WHERE ass_filter.artist = :artist_filter_id',
        ))->setParameter('artist_filter_id', $artistEntity->getId());
    }

    private function applyAlbumIdFilter(\Doctrine\ORM\QueryBuilder $qb, string $albumPublicId): void
    {
        $albumEntity = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->findOneBy(['publicId' => PublicId::fromString($albumPublicId)]);

        if ($albumEntity === null) {
            $qb->andWhere('1 = 0');
            return;
        }

        $qb->andWhere('s.album = :album_filter_id')
            ->setParameter('album_filter_id', $albumEntity->getId());
    }

    private function resolveSortColumn(string $sortField, \Doctrine\ORM\QueryBuilder $qb): string
    {
        return match ($sortField) {
            'title' => 's.title',
            'artist' => $this->joinArtistForSort($qb),
            'album' => $this->joinAlbumForSort($qb),
            'year' => $this->joinAlbumYearForSort($qb),
            'added' => 's.createdAt',
            default => 's.title',
        };
    }

    private function joinArtistForSort(\Doctrine\ORM\QueryBuilder $qb): string
    {
        $qb->leftJoin(ArtistSongEntity::class, 'ass_sort', 'WITH', 'ass_sort.song = s.id')
            ->leftJoin(ArtistEntity::class, 'ar_sort', 'WITH', 'ar_sort.id = ass_sort.artist');

        // Prevent duplicate rows when a song has multiple artists
        $qb->groupBy('s.id');

        return 'MIN(ar_sort.name)';
    }

    private function joinAlbumForSort(\Doctrine\ORM\QueryBuilder $qb): string
    {
        $qb->leftJoin(AlbumEntity::class, 'al_sort', 'WITH', 'al_sort.id = s.album');
        return 'al_sort.title';
    }

    private function buildValueExtractor(string $sortField): \Closure
    {
        return match ($sortField) {
            'artist' => function (SongEntity $e): array {
                $artistName = $this->getArtistNameForSong($e->getId()) ?? '';
                return ['sort' => $artistName, 'id' => $e->getId()->toString()];
            },
            'album' => fn (SongEntity $e): array => ['sort' => $e->getAlbum()->getTitle(), 'id' => $e->getId()->toString()],
            'year' => fn (SongEntity $e): array => ['sort' => (string) ($e->getAlbum()->getYear() ?? ''), 'id' => $e->getId()->toString()],
            'added' => fn (SongEntity $e): array => ['sort' => $e->getCreatedAt()->format('c'), 'id' => $e->getId()->toString()],
            default => fn (SongEntity $e): array => ['sort' => $e->getTitle(), 'id' => $e->getId()->toString()],
        };
    }

    private function joinAlbumYearForSort(\Doctrine\ORM\QueryBuilder $qb): string
    {
        $qb->leftJoin(AlbumEntity::class, 'al_y_sort', 'WITH', 'al_y_sort.id = s.album');
        return 'al_y_sort.year';
    }

    private function applyGenreFilter(\Doctrine\ORM\QueryBuilder $qb, string $operator, mixed $value): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        $isExclusion = str_starts_with($value, '!');
        $genreNames = $isExclusion ? substr($value, 1) : $value;

        if ($genreNames === '') {
            return;
        }

        $names = array_filter(array_map('trim', explode(',', $genreNames)));
        if ($names === []) {
            return;
        }

        if ($isExclusion) {
            // Subquery to avoid count inflation in cursor pagination
            $qb->andWhere($qb->expr()->notIn(
                's.id',
                sprintf(
                    'SELECT IDENTITY(gs.song) FROM App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity gs JOIN gs.genre g WHERE g.name IN (:excluded_genres)',
                ),
            ))->setParameter('excluded_genres', $names);
        } else {
            $qb->andWhere($qb->expr()->in(
                's.id',
                sprintf(
                    'SELECT IDENTITY(gs.song) FROM App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity gs JOIN gs.genre g WHERE g.name IN (:included_genres)',
                ),
            ))->setParameter('included_genres', $names);
        }
    }

    private function applyPublicIdsFilter(\Doctrine\ORM\QueryBuilder $qb, string $publicIds): void
    {
        if ($publicIds === '') {
            return;
        }

        $ids = array_filter(array_map('trim', explode(',', $publicIds)));
        if ($ids === []) {
            return;
        }

        $publicIdObjects = array_map(fn(string $id) => PublicId::fromString($id), $ids);

        $qb->andWhere($qb->expr()->in('s.publicId', ':public_ids_filter'))
            ->setParameter('public_ids_filter', $publicIdObjects);
    }

    /**
     * Convert a SongEntity to a domain Song model (public static for use by AlbumRepository).
     */
    public static function entityToDomain(SongEntity $entity): Song
    {
        return Song::reconstitute(new SongState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            album: $entity->getAlbum()->getId(),
            albumPublicId: $entity->getAlbum()->getPublicId(),
            title: $entity->getTitle(),
            path: $entity->getPath(),
            size: $entity->getSize(),
            mimeType: $entity->getMimeType(),
            length: $entity->getLength(),
            lyrics: $entity->getLyrics(),
            track: $entity->getTrack(),
            disc: $entity->getDisc(),
            year: $entity->getYear(),
            comment: $entity->getComment(),
            hash: $entity->getHash(),
            bitrate: $entity->getBitrate(),
            sampleRate: $entity->getSampleRate(),
            channels: $entity->getChannels(),
            codec: $entity->getCodec(),
            explicit: $entity->isExplicit(),
            energy: $entity->getEnergy(),
            danceability: $entity->getDanceability(),
            valence: $entity->getValence(),
            acousticness: $entity->getAcousticness(),
            instrumentalness: $entity->getInstrumentalness(),
            liveness: $entity->getLiveness(),
            spechiness: $entity->getSpechiness(),
            loudness: $entity->getLoudness(),
            mbid: $entity->getMbid(),
            discogsId: $entity->getDiscogsId(),
            spotifyId: $entity->getSpotifyId(),
            lockedFields: $entity->getLockedFields(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function toDomain(SongEntity $entity): Song
    {
        return self::entityToDomain($entity);
    }

    private function findEntityOrCreate(Song $song): SongEntity
    {
        $existing = $this->entityManager
            ->getRepository(SongEntity::class)
            ->find($song->getId());

        if ($existing !== null) {
            return $existing;
        }

        $albumEntity = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->find($song->getAlbumId());

        return new SongEntity(
            $song->getPublicId(),
            $albumEntity,
            $song->getTitle(),
            $song->getPath(),
            $song->getSize(),
            $song->getMimeType(),
            id: $song->getId(),
        );
    }

    private function syncToEntity(Song $song, SongEntity $entity): void
    {
        $entity->setTitle($song->getTitle());
        $entity->setPath($song->getPath());
        $entity->setSize($song->getSize());
        $entity->setMimeType($song->getMimeType());
        $entity->setLength($song->getLength());
        $entity->setLyrics($song->getLyrics());
        $entity->setTrack($song->getTrack());
        $entity->setDisc($song->getDisc());
        $entity->setYear($song->getYear());
        $entity->setComment($song->getComment());
        $entity->setHash($song->getHash());
        $entity->setBitrate($song->getBitrate());
        $entity->setSampleRate($song->getSampleRate());
        $entity->setChannels($song->getChannels());
        $entity->setCodec($song->getCodec());
        $entity->setExplicit($song->isExplicit());
        $entity->setEnergy($song->getEnergy());
        $entity->setDanceability($song->getDanceability());
        $entity->setValence($song->getValence());
        $entity->setAcousticness($song->getAcousticness());
        $entity->setInstrumentalness($song->getInstrumentalness());
        $entity->setLiveness($song->getLiveness());
        $entity->setSpechiness($song->getSpechiness());
        $entity->setLoudness($song->getLoudness());
        $entity->setMbid($song->getMbid());
        $entity->setDiscogsId($song->getDiscogsId());
        $entity->setSpotifyId($song->getSpotifyId());
        $entity->setLockedFields($song->getLockedFields());
    }
}
