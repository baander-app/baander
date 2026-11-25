<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Repository;

use App\Radio\Domain\Model\RadioStation\RadioStation;
use App\Radio\Domain\Model\RadioStation\RadioStationState;
use App\Radio\Domain\Model\RadioStation\Stream;
use App\Radio\Domain\Repository\RadioStation\RadioStationRepositoryInterface;
use App\Radio\Infrastructure\Doctrine\Entity\RadioSourceEntity;
use App\Radio\Infrastructure\Doctrine\Entity\RadioStationEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final class RadioStationDoctrineRepository implements RadioStationRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function find(Uuid $id): ?RadioStation
    {
        $entity = $this->entityManager->find(RadioStationEntity::class, $id);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findBySourceAndExternalId(Uuid $sourceId, string $externalId): ?RadioStation
    {
        $entity = $this->entityManager
            ->getRepository(RadioStationEntity::class)
            ->findOneBy(['source' => $sourceId, 'externalId' => $externalId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?RadioStation
    {
        $entity = $this->entityManager->getRepository(RadioStationEntity::class)
            ->findOneBy(['publicId' => $publicId->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /** @return list<RadioStation> */
    public function findBySourceAndCountry(Uuid $sourceId, string $countryCode): array
    {
        $entities = $this->entityManager
            ->getRepository(RadioStationEntity::class)
            ->findBy(['source' => $sourceId, 'country' => $countryCode]);

        return array_map($this->toDomain(...), $entities);
    }

    /** @return list<RadioStation> */
    public function findMatching(?string $countryCode, ?string $query, array $publicIds, int $limit, int $offset): array
    {
        /** @var list<RadioStationEntity> $entities */
        $entities = $this->matching('s', $countryCode, $query, $publicIds)
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map($this->toDomain(...), $entities);
    }

    public function countMatching(?string $countryCode, ?string $query, array $publicIds): int
    {
        return (int) $this->matching('COUNT(s.id)', $countryCode, $query, $publicIds)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param list<PublicId> $publicIds */
    private function matching(string $select, ?string $countryCode, ?string $query, array $publicIds): QueryBuilder
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(RadioStationEntity::class, 's');

        if ($countryCode !== null) {
            $builder->andWhere('s.country = :country')->setParameter('country', $countryCode);
        }
        if ($query !== null) {
            // Backslash escapes LIKE's wildcards, so the text matches as written.
            $builder->andWhere("LOWER(s.name) LIKE :query ESCAPE '\\'")
                ->setParameter('query', '%' . addcslashes(mb_strtolower($query), '%_\\') . '%');
        }
        if ($publicIds !== []) {
            $builder->andWhere('s.publicId IN (:publicIds)')
                ->setParameter('publicIds', array_map(static fn (PublicId $id): string => $id->toString(), $publicIds));
        }

        return $builder;
    }

    public function save(RadioStation $station): void
    {
        $entity = $this->findEntityOrCreate($station);
        $this->syncToEntity($station, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function remove(RadioStation $station): void
    {
        $entity = $this->entityManager->find(RadioStationEntity::class, $station->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(RadioStation $station): RadioStationEntity
    {
        $existing = $this->entityManager->find(RadioStationEntity::class, $station->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new RadioStationEntity(
            id: $station->getId(),
            source: $this->entityManager->getReference(RadioSourceEntity::class, $station->getSourceId()),
            externalId: $station->getExternalId(),
            name: $station->getName(),
            country: $station->getCountry(),
        );
    }

    private function syncToEntity(RadioStation $station, RadioStationEntity $entity): void
    {
        $entity->setPublicId($station->getPublicId());
        $state = $station->getState();
        $entity->setName($state->name);
        $entity->setCountry($state->country);
        $entity->setLanguage($state->language);
        $entity->setGenres($state->genres);
        $entity->setTags($state->tags);
        $entity->setStreams(array_map(fn (Stream $s) => [
            'url' => $s->url,
            'format' => $s->format,
            'bitrate' => $s->bitrate,
            'reliability' => $s->reliability,
        ], $state->streams));
        $entity->setLogo($state->logo);
        $entity->setWebsite($state->website);
        $entity->setLastCheckedAt($state->lastCheckedAt);
        $entity->setUpdatedAt($state->updatedAt);
    }

    private function toDomain(RadioStationEntity $entity): RadioStation
    {
        $streams = array_map(fn (array $s) => new Stream(
            url: $s['url'],
            format: $s['format'],
            bitrate: $s['bitrate'],
            reliability: $s['reliability'],
        ), $entity->getStreams());

        return RadioStation::reconstitute(new RadioStationState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            sourceId: $entity->getSourceId(),
            externalId: $entity->getExternalId(),
            name: $entity->getName(),
            country: $entity->getCountry(),
            language: $entity->getLanguage(),
            genres: $entity->getGenres(),
            tags: $entity->getTags(),
            streams: $streams,
            logo: $entity->getLogo(),
            website: $entity->getWebsite(),
            lastCheckedAt: $entity->getLastCheckedAt(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }
}
