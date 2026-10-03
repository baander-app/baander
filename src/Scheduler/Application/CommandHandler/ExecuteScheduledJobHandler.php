<?php

declare(strict_types=1);

namespace App\Scheduler\Application\CommandHandler;

use App\Scheduler\Application\Command\ExecuteScheduledJobCommand;
use App\Scheduler\Application\DTO\SchedulerOccurrence;
use App\Scheduler\Application\DTO\SchedulerOccurrenceOrigin;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Application\Port\ScheduledConsoleExecutorInterface;
use App\Scheduler\Application\Exception\ScheduledConsoleCompletionUnknown;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Throwable;

#[AsMessageHandler]
final class ExecuteScheduledJobHandler
{
    public function __construct(
        private readonly ScheduledJobPortInterface $scheduledJobService,
        private readonly SchedulerRegistry $registry,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly ScheduledConsoleExecutorInterface $consoleExecutor,
    ) {
    }

    public function __invoke(ExecuteScheduledJobCommand $command): void
    {
        throw new UnrecoverableMessageHandlingException('Legacy scheduled job wrappers are retired; record a durable scheduler occurrence instead.');
    }

    /** Normal return includes cancellation and recorded failure; it is not a success receipt. */
    public function executeOccurrence(SchedulerOccurrence $occurrence): void
    {
        $this->execute(new ExecuteScheduledJobCommand(
            $occurrence->jobId->toString(),
            $occurrence->jobType->value,
            $occurrence->command,
            $occurrence->parameters,
        ), $occurrence->origin === SchedulerOccurrenceOrigin::Manual);
    }

    private function execute(ExecuteScheduledJobCommand $command, bool $manual): void
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($command->jobId));
        if ($job === null) {
            $this->logger->warning('Scheduled job not found', ['jobId' => $command->jobId]);

            return;
        }

        if (
            (!$manual && $job->getStatus() !== ScheduleStatus::Active)
            || $job->getJobType()->value !== $command->jobType
            || $job->getCommand() !== $command->command
            || $job->getParameters() !== $command->parameters
            || json_encode($job->getParameters(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode($command->parameters, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
        ) {
            return;
        }

        // Validate command is still in the registry (may have been removed)
        $allowed = match ($command->jobType) {
            JobType::Messenger->value => $this->registry->isMessengerCommandAllowed($command->command),
            JobType::Console->value => $this->registry->isConsoleCommandAllowed($command->command),
        };

        if (!$allowed) {
            $this->logger->error('Scheduled job command not in registry — skipping', [
                'jobId' => $command->jobId,
                'jobType' => $command->jobType,
                'command' => $command->command,
            ]);

            $job->markFailed(sprintf('Command "%s" is not registered as schedulable.', $command->command));
            $this->scheduledJobService->save($job);

            return;
        }

        $job->markRunning();
        $this->scheduledJobService->save($job);

        $unknownCompletion = null;
        try {
            $result = match ($command->jobType) {
                JobType::Messenger->value => $this->dispatchMessenger($command),
                JobType::Console->value => $this->consoleExecutor->execute($command->command, $command->parameters),
            };

            $job->markSuccess($result);
        } catch (ScheduledConsoleCompletionUnknown $e) {
            // The child may still be running. Persist the uncertainty and stop
            // future cron dispatches; Messenger retries could duplicate its work.
            $job->markFailed($e->getMessage());
            if ($job->getStatus() === ScheduleStatus::Active) {
                $job->pause();
            }
            $unknownCompletion = $e;
        } catch (Throwable $e) {
            $this->logger->error('Scheduled job failed', [
                'jobId' => $command->jobId,
                'command' => $command->command,
                'error' => $e->getMessage(),
            ]);
            $job->markFailed($e->getMessage());
        }

        try {
            $this->scheduledJobService->save($job);
        } catch (Throwable $persistenceError) {
            if ($unknownCompletion !== null) {
                throw new ScheduledConsoleCompletionUnknown(
                    'Console completion unknown; failed to persist paused schedule. Deployment containment required.',
                    previous: $persistenceError,
                );
            }
            throw $persistenceError;
        }
        if ($unknownCompletion !== null) {
            throw $unknownCompletion;
        }
    }

    private function dispatchMessenger(ExecuteScheduledJobCommand $command): string
    {
        $messageClass = $command->command;

        if (!class_exists($messageClass)) {
            throw new \RuntimeException(sprintf('Messenger message class "%s" does not exist.', $messageClass));
        }

        $message = new ($messageClass)(...$command->parameters);
        $this->messageBus->dispatch($message);

        return 'dispatched';
    }

}
