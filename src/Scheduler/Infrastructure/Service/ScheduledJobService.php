<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Service;

use App\Scheduler\Application\DTO\ScheduledJobInput;
use App\Scheduler\Application\Exception\ScheduledJobConflict as AdministrationConflict;
use App\Scheduler\Application\Port\ScheduledJobAdministrationInterface;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Exception\ScheduledJobConflict;
use App\Scheduler\Domain\Model\ScheduledJob;
use App\Scheduler\Domain\Repository\ScheduledJobRepositoryInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;
use Cron\CronExpression;
use InvalidArgumentException;

final class ScheduledJobService implements ScheduledJobPortInterface, ScheduledJobAdministrationInterface
{
    public function __construct(
        private readonly ScheduledJobRepositoryInterface $repository,
        private readonly SchedulerRegistry $registry,
    ) {
    }

    /** @param array<string, mixed> $parameters */
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

    /** @return ScheduledJob[] */
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

    public function createJob(ScheduledJobInput $input): ScheduledJob
    {
        $jobType = $this->validateAdministrationInput($input);

        try {
            return $this->create(
                $input->name,
                $input->expression,
                $jobType,
                $input->command,
                $input->description,
                $input->parameters,
            );
        } catch (ScheduledJobConflict $error) {
            throw new AdministrationConflict($error);
        }
    }

    public function updateJob(Uuid $id, ScheduledJobInput $input): ?ScheduledJob
    {
        $job = $this->getById($id);
        if ($job === null) {
            return null;
        }

        $jobType = $this->validateAdministrationInput($input);
        $this->validateCommand($jobType, $input->command, $input->parameters);
        $job->update(
            $input->name,
            $input->expression,
            $jobType,
            $input->command,
            $input->description,
            $input->parameters,
        );
        $this->saveAdministrationJob($job);

        return $job;
    }

    public function deleteById(Uuid $id): bool
    {
        $job = $this->getById($id);
        if ($job === null) {
            return false;
        }

        try {
            $this->delete($job);
        } catch (ScheduledJobConflict $error) {
            throw new AdministrationConflict($error);
        }

        return true;
    }

    public function pause(Uuid $id): ?ScheduledJob
    {
        $job = $this->getById($id);
        if ($job === null) {
            return null;
        }

        $job->pause();
        $this->saveAdministrationJob($job);

        return $job;
    }

    public function resume(Uuid $id): ?ScheduledJob
    {
        $job = $this->getById($id);
        if ($job === null) {
            return null;
        }

        $job->resume();
        $this->saveAdministrationJob($job);

        return $job;
    }

    public function enable(Uuid $id): ?ScheduledJob
    {
        $job = $this->getById($id);
        if ($job === null) {
            return null;
        }

        $job->enable();
        $this->saveAdministrationJob($job);

        return $job;
    }

    public function disable(Uuid $id): ?ScheduledJob
    {
        $job = $this->getById($id);
        if ($job === null) {
            return null;
        }

        $job->disable();
        $this->saveAdministrationJob($job);

        return $job;
    }

    public function availableCommands(): array
    {
        return [
            'messenger' => $this->registry->getMessengerCommands(),
            'console' => $this->registry->getConsoleCommands(),
        ];
    }

    private function saveAdministrationJob(ScheduledJob $job): void
    {
        try {
            $this->save($job);
        } catch (ScheduledJobConflict $error) {
            throw new AdministrationConflict($error);
        }
    }

    private function validateAdministrationInput(ScheduledJobInput $input): JobType
    {
        if (trim($input->name) === '' || mb_strlen($input->name) > 255) {
            throw new InvalidArgumentException(
                'Scheduled job name must contain between 1 and 255 characters.',
            );
        }
        if (!CronExpression::isValidExpression($input->expression)) {
            throw new InvalidArgumentException('Invalid cron expression.');
        }
        return JobType::tryFrom($input->jobType)
            ?? throw new InvalidArgumentException('Invalid scheduled job type.');
    }

    /** @param array<string, mixed> $parameters */
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
