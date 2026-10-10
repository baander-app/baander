<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

trait RefreshedEntityTrait
{
    /**
     * Loads an entity by id as stored now. find() answers from the identity map; the refresh
     * hint overwrites a managed entity with the stored row instead.
     *
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return T|null
     */
    private function findRefreshedEntity(EntityManagerInterface $em, string $entityClass, Uuid $id): ?object
    {
        $entity = $em
            ->getRepository($entityClass)
            ->createQueryBuilder('fresh')
            ->where('fresh.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof $entityClass ? $entity : null;
    }
}
