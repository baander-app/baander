<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Repository;

use App\Radio\Domain\Model\RadioStation\RadioStation;
use App\Radio\Domain\Model\RadioStation\RadioStationState;
use App\Radio\Domain\Model\RadioStation\Stream;
use App\Radio\Domain\Repository\RadioStation\RadioStationRepositoryInterface;
use App\Radio\Infrastructure\Doctrine\Entity\RadioStationEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

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
            ->findOneBy(['sourceId' => $sourceId, 'externalId' => $externalId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByCountry(string $countryCode): array
    {
        $entities = $this->entityManager
            ->getRepository(RadioStationEntity::class)
            ->findBy(['country' => $countryCode]);

        return array_map($this->toDomain(...), $entities);
    }

    public function findBySourceAndCountry(Uuid $sourceId, string $countryCode): array
    {
        $entities = $this->entityManager
            ->getRepository(RadioStationEntity::class)
            ->findBy(['sourceId' => $sourceId, 'country' => $countryCode]);

        return array_map($this->toDomain(...), $entities);
    }

    public function search(string $query, ?string $countryCode = null): array
    {
        $qb = $this->entityManager
            ->getRepository(RadioStationEntity::class)
            ->createQueryBuilder('s');

        $qb->where($qb->expr()->like('LOWER(s.name)', ':query'))
            ->setParameter('query', '%' . mb_strtolower($query) . '%');

        if ($countryCode !== null) {
            $qb->andWhere('s.country = :country')
                ->setParameter('country', $countryCode);
        }

        $entities = $qb->getQuery()->getResult();

        return array_map($this->toDomain(...), $entities);
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
            sourceId: $station->getSourceId(),
            externalId: $station->getExternalId(),
            name: $station->getName(),
            country: $station->getCountry(),
        );
    }

    private function syncToEntity(RadioStation $station, RadioStationEntity $entity): void
    {
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
