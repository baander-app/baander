<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Service;

use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

final class ScheduledJobService implements ScheduledJobPortInterface
{
    public function __construct(
        private readonly ScheduledJobRepositoryInterface $repository,
        private readonly SchedulerRegistry $registry,
    ) {
    }

    public function create(
        string $name,
        string $expression,
        JobType $jobType,
        string $command,
        ?string $description = null,
        array $parameters = [],
    ): ScheduledJob {
        $this->validateCommand($jobType, $command, $parameters);

        $job = ScheduledJob::create($name, $expression, $jobType, $command, $description, $parameters);
        $this->repository->save($job);

        return $job;
    }

    public function getById(Uuid $id): ?ScheduledJob
    {
        return $this->repository->findByUuid($id);
    }

    public function findAll(): array
    {
        return $this->repository->findAll();
    }

    public function findByStatus(ScheduleStatus $status): array
    {
        return $this->repository->findByStatus($status);
    }

    public function save(ScheduledJob $job): void
    {
        $this->repository->save($job);
    }

    public function delete(ScheduledJob $job): void
    {
        $this->repository->delete($job);
    }

    private function validateCommand(JobType $jobType, string $command, array $parameters): void
    {
        $schema = match ($jobType) {
            JobType::Messenger => $this->validateMessengerCommand($command),
            JobType::Console => $this->validateConsoleCommand($command),
        };

        $allowedKeys = array_keys($schema);
        foreach ($parameters as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid parameters for command "%s": "%s" is not in the allowed schema.',
                    $command,
                    $key,
                ));
            }

            $this->validateParameterType($key, $value, $schema[$key]['type'] ?? 'string');
        }

        foreach ($schema as $key => $definition) {
            if ($definition['required'] && !array_key_exists($key, $parameters)) {
                throw new InvalidArgumentException(sprintf(
                    'Missing required parameter "%s" for command "%s".',
                    $key,
                    $command,
                ));
            }
        }
    }

    /**
     * @return array<string, array{type: string, required: bool, description?: string, default?: mixed}>
     */
    private function validateMessengerCommand(string $command): array
    {
        if (!$this->registry->isMessengerCommandAllowed($command)) {
            throw new InvalidArgumentException(sprintf(
                'Command "%s" is not registered as a schedulable messenger command.',
                $command,
            ));
        }

        return $this->registry->getMessengerParameterSchema($command);
    }

    /**
     * @return array<string, array{type: string, required: bool, description?: string, default?: mixed}>
     */
    private function validateConsoleCommand(string $command): array
    {
        if (!$this->registry->isConsoleCommandAllowed($command)) {
            throw new InvalidArgumentException(sprintf(
                'Command "%s" is not registered as a schedulable console command.',
                $command,
            ));
        }

        return $this->registry->getConsoleParameterSchema($command);
    }

    private function validateParameterType(string $key, mixed $value, string $expectedType): void
    {
        $actualType = match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'array',
            is_string($value) => 'string',
            default => gettype($value),
        };

        if ($actualType !== $expectedType) {
            throw new InvalidArgumentException(sprintf(
                'Invalid parameter "%s": expected %s, got %s.',
                $key,
                $expectedType,
                $actualType,
            ));
        }
    }
}
