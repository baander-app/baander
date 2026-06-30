<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\ArtistState;
use App\Catalog\Domain\Repository\ArtistRepositoryInterface;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Repository\PgroongaSearchTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pure domain repository for artists.
 */
final class ArtistRepository implements ArtistRepositoryInterface
{
    use PgroongaSearchTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Artist $artist): void
    {
        $entity = $this->findEntityOrCreate($artist);
        $this->syncToEntity($artist, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(Artist $artist): void
    {
        $entity = $this->findEntityOrCreate($artist);
        $this->syncToEntity($artist, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Artist
    {
        $entity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?Artist
    {
        $entity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $entities = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findBy(['id' => $uuids]);

        $result = [];
        foreach ($entities as $entity) {
            $artist = $this->toDomain($entity);
            $result[$artist->getId()->toString()] = $artist;
        }

        return $result;
    }

    public function findByMbid(?MusicbrainzId $mbid): ?Artist
    {
        if ($mbid === null || $mbid->isEmpty()) {
            return null;
        }

        $entity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['mbid' => $mbid->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function search(SearchOptions $options): SearchResult
    {
        if (!$options->hasQuery()) {
            // Listing mode: return all artists with offset/limit pagination
            $qb = $this->entityManager
                ->getRepository(ArtistEntity::class)
                ->createQueryBuilder('a');

            $this->applyArtistFilters($qb, $options->getFilters());

            $countQb = clone $qb;
            $countQb->resetDQLPart('select')
                ->resetDQLPart('orderBy')
                ->setFirstResult(0)
                ->setMaxResults(null)
                ->select('COUNT(a)');
            $total = (int) $countQb->getQuery()->getSingleScalarResult();

            $this->applyArtistSort($qb, $options);

            $qb->setMaxResults($options->getLimit())
                ->setFirstResult($options->getOffset());

            $entities = $qb->getQuery()->getResult();
            $artists = array_map(fn(ArtistEntity $entity) => $this->toDomain($entity), $entities);

            return SearchResult::create($artists, $total);
        }

        $result = $this->buildScoredQuery(
            $options,
            $this->entityManager,
            ArtistEntity::class,
            'artists',
            'name',
        );

        $artists = array_map(fn(ArtistEntity $entity) => $this->toDomain($entity), $result['entities']);

        return SearchResult::create($artists, $result['total'], $result['highestScore']);
    }

    public function findByName(string $name): ?Artist
    {
        $entity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['name' => $name]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findOrCreateByName(string $name): Artist
    {
        $existing = $this->findByName($name);

        if ($existing !== null) {
            return $existing;
        }

        $artist = Artist::create($name);
        $entity = new ArtistEntity(
            $artist->getPublicId(),
            $artist->getName(),
        );
        $this->entityManager->persist($entity);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findByName($name);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }

        return $this->toDomain($entity);
    }

    public function count(): int
    {
        return (int) $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->count([]);
    }

    public function delete(Artist $artist): void
    {
        $entity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->find($artist->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    // --- Internal ---

    /**
     * @param list<array{field: string, operator: string, value: mixed}> $filters
     */
    private function applyArtistFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        foreach ($filters as $filter) {
            match ($filter['field']) {
                'genre' => $this->applyGenreFilter($qb, $filter['value']),
                default => null,
            };
        }
    }

    private function applyGenreFilter(\Doctrine\ORM\QueryBuilder $qb, string $genreSlug): void
    {
        $qb->andWhere($qb->expr()->in(
            'a.id',
            'SELECT IDENTITY(ass_gf.artist) FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity ass_gf ' .
            'JOIN App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity gs_gf WITH gs_gf.song = ass_gf.song ' .
            'JOIN gs_gf.genre g_gf WHERE g_gf.slug = :genre_slug',
        ))->setParameter('genre_slug', $genreSlug);
    }

    private function applyArtistSort(\Doctrine\ORM\QueryBuilder $qb, SearchOptions $options): void
    {
        if (!$options->hasSort()) {
            $qb->orderBy('a.name', 'ASC');
            return;
        }

        $direction = strtoupper($options->getSortOrder());

        match ($options->getSortField()) {
            'name' => $qb->orderBy('a.name', $direction),
            default => $qb->orderBy('a.name', 'ASC'),
        };
    }

    private function findEntityOrCreate(Artist $artist): ArtistEntity
    {
        $existing = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->find($artist->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new ArtistEntity(
            $artist->getPublicId(),
            $artist->getName(),
            id: $artist->getId(),
        );
    }

    private function toDomain(ArtistEntity $entity): Artist
    {
        return Artist::reconstitute(new ArtistState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            name: $entity->getName(),
            country: $entity->getCountry(),
            gender: $entity->getGender(),
            type: $entity->getType(),
            lifeSpanBegin: $entity->getLifeSpanBegin(),
            lifeSpanEnd: $entity->getLifeSpanEnd(),
            disambiguation: $entity->getDisambiguation(),
            sortName: $entity->getSortName(),
            biography: $entity->getBiography(),
            mbid: $entity->getMbid(),
            discogsId: $entity->getDiscogsId(),
            spotifyId: $entity->getSpotifyId(),
            coverImageId: $entity->getCoverImage()?->getId(),
            lockedFields: $entity->getLockedFields(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(Artist $artist, ArtistEntity $entity): void
    {
        $entity->setName($artist->getName());
        $entity->setCountry($artist->getCountry());
        $entity->setGender($artist->getGender());
        $entity->setType($artist->getType());
        $entity->setLifeSpanBegin($artist->getLifeSpanBegin());
        $entity->setLifeSpanEnd($artist->getLifeSpanEnd());
        $entity->setDisambiguation($artist->getDisambiguation());
        $entity->setSortName($artist->getSortName());
        $entity->setBiography($artist->getBiography());
        $entity->setMbid($artist->getMbid());
        $entity->setDiscogsId($artist->getDiscogsId());
        $entity->setSpotifyId($artist->getSpotifyId());
        $entity->setLockedFields($artist->getLockedFields());

        // Sync cover image relationship
        if ($artist->getCoverImageId() !== null) {
            $imageEntity = $this->entityManager
                ->getRepository(\App\Media\Infrastructure\Doctrine\Entity\ImageEntity::class)
                ->find($artist->getCoverImageId());
            $entity->setCoverImage($imageEntity);
        } else {
            $entity->setCoverImage(null);
        }
    }

    public function addSongToArtist(Uuid $artistId, Uuid $songId, string $role): void
    {
        $artistEntity = $this->entityManager->getRepository(ArtistEntity::class)->find($artistId);
        $songEntity = $this->entityManager->getRepository(SongEntity::class)->find($songId);

        if ($artistEntity === null || $songEntity === null) {
            return;
        }

        $existing = $this->entityManager->getRepository(ArtistSongEntity::class)->findOneBy([
            'artist' => $artistEntity,
            'song' => $songEntity,
            'role' => $role,
        ]);

        if ($existing !== null) {
            return;
        }

        $this->entityManager->persist(new ArtistSongEntity($artistEntity, $songEntity, $role));
        $this->entityManager->flush();
    }

    public function removeSongFromArtist(Uuid $artistId, Uuid $songId): void
    {
        $artistEntity = $this->entityManager->getRepository(ArtistEntity::class)->find($artistId);
        $songEntity = $this->entityManager->getRepository(SongEntity::class)->find($songId);

        if ($artistEntity === null || $songEntity === null) {
            return;
        }

        $links = $this->entityManager->getRepository(ArtistSongEntity::class)->findBy([
            'artist' => $artistEntity,
            'song' => $songEntity,
        ]);

        foreach ($links as $link) {
            $this->entityManager->remove($link);
        }

        $this->entityManager->flush();
    }

    public function updateSongRole(Uuid $artistId, Uuid $songId, string $role): void
    {
        $artistEntity = $this->entityManager->getRepository(ArtistEntity::class)->find($artistId);
        $songEntity = $this->entityManager->getRepository(SongEntity::class)->find($songId);

        if ($artistEntity === null || $songEntity === null) {
            return;
        }

        $link = $this->entityManager->getRepository(ArtistSongEntity::class)->findOneBy([
            'artist' => $artistEntity,
            'song' => $songEntity,
        ]);

        if ($link !== null) {
            $link->setRole($role);
            $this->entityManager->flush();
        }
    }

    public function addAlbumToArtist(Uuid $artistId, Uuid $albumId, string $role): void
    {
        $artistEntity = $this->entityManager->getRepository(ArtistEntity::class)->find($artistId);
        $albumEntity = $this->entityManager->getRepository(AlbumEntity::class)->find($albumId);

        if ($artistEntity === null || $albumEntity === null) {
            return;
        }

        $existing = $this->entityManager->getRepository(ArtistAlbumEntity::class)->findOneBy([
            'artist' => $artistEntity,
            'album' => $albumEntity,
            'role' => $role,
        ]);

        if ($existing !== null) {
            return;
        }

        $this->entityManager->persist(new ArtistAlbumEntity($artistEntity, $albumEntity, $role));
        $this->entityManager->flush();
    }

    public function removeAlbumFromArtist(Uuid $artistId, Uuid $albumId): void
    {
        $artistEntity = $this->entityManager->getRepository(ArtistEntity::class)->find($artistId);
        $albumEntity = $this->entityManager->getRepository(AlbumEntity::class)->find($albumId);

        if ($artistEntity === null || $albumEntity === null) {
            return;
        }

        $links = $this->entityManager->getRepository(ArtistAlbumEntity::class)->findBy([
            'artist' => $artistEntity,
            'album' => $albumEntity,
        ]);

        foreach ($links as $link) {
            $this->entityManager->remove($link);
        }

        $this->entityManager->flush();
    }

    public function updateAlbumRole(Uuid $artistId, Uuid $albumId, string $role): void
    {
        $artistEntity = $this->entityManager->getRepository(ArtistEntity::class)->find($artistId);
        $albumEntity = $this->entityManager->getRepository(AlbumEntity::class)->find($albumId);

        if ($artistEntity === null || $albumEntity === null) {
            return;
        }

        $link = $this->entityManager->getRepository(ArtistAlbumEntity::class)->findOneBy([
            'artist' => $artistEntity,
            'album' => $albumEntity,
        ]);

        if ($link !== null) {
            $link->setRole($role);
            $this->entityManager->flush();
        }
    }
}
