<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Doctrine\Repository;

use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Model\ScheduledJobState;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Scheduler\Infrastructure\Doctrine\Entity\ScheduledJobEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class ScheduledJobRepository implements ScheduledJobRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ScheduledJob $job): void
    {
        $entity = $this->findEntityOrCreate($job);
        $this->syncToEntity($job, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?ScheduledJob
    {
        $entity = $this->entityManager
            ->getRepository(ScheduledJobEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findAll(): array
    {
        $entities = $this->entityManager
            ->getRepository(ScheduledJobEntity::class)
            ->findBy([], ['createdAt' => 'DESC']);

        return array_map(fn(ScheduledJobEntity $e) => $this->toDomain($e), $entities);
    }

    public function findByStatus(ScheduleStatus $status): array
    {
        $entities = $this->entityManager
            ->getRepository(ScheduledJobEntity::class)
            ->findBy(['status' => $status->value]);

        return array_map(fn(ScheduledJobEntity $e) => $this->toDomain($e), $entities);
    }

    public function delete(ScheduledJob $job): void
    {
        $entity = $this->entityManager
            ->getRepository(ScheduledJobEntity::class)
            ->find($job->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(ScheduledJob $job): ScheduledJobEntity
    {
        $entity = $this->entityManager
            ->getRepository(ScheduledJobEntity::class)
            ->find($job->getId());

        return $entity ?? new ScheduledJobEntity($job->getId());
    }

    private function toDomain(ScheduledJobEntity $entity): ScheduledJob
    {
        return ScheduledJob::reconstitute(new ScheduledJobState(
            id: $entity->getId(),
            name: $entity->getName(),
            expression: $entity->getExpression(),
            jobType: JobType::from($entity->getJobType()),
            command: $entity->getCommand(),
            status: ScheduleStatus::from($entity->getStatus()),
            description: $entity->getDescription(),
            parameters: $entity->getParameters(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            lastRunAt: $entity->getLastRunAt(),
            nextRunAt: $entity->getNextRunAt(),
            lastResult: $entity->getLastResult(),
            runCount: $entity->getRunCount(),
            lastFailureAt: $entity->getLastFailureAt(),
            lastError: $entity->getLastError(),
        ));
    }

    private function syncToEntity(ScheduledJob $job, ScheduledJobEntity $entity): void
    {
        $state = $job->getState();

        $entity->setName($state->name);
        $entity->setExpression($state->expression);
        $entity->setJobType($state->jobType->value);
        $entity->setCommand($state->command);
        $entity->setStatus($state->status->value);
        $entity->setDescription($state->description);
        $entity->setParameters($state->parameters);
        $entity->setCreatedAt($state->createdAt);
        $entity->setUpdatedAt($state->updatedAt);
        $entity->setLastRunAt($state->lastRunAt);
        $entity->setNextRunAt($state->nextRunAt);
        $entity->setLastResult($state->lastResult);
        $entity->setRunCount($state->runCount);
        $entity->setLastFailureAt($state->lastFailureAt);
        $entity->setLastError($state->lastError);
    }
}
