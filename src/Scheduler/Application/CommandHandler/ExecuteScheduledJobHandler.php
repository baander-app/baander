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
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

#[AsMessageHandler]
final class ExecuteScheduledJobHandler
{
    public function __construct(
        private readonly ScheduledJobPortInterface $scheduledJobService,
        private readonly SchedulerRegistry $registry,
        private readonly MessageBusInterface $messageBus,
        private readonly CpuProcessPoolInterface $cpuPool,
        private readonly RedisClientFactory $redis,
        private readonly LoggerInterface $logger,
        private readonly ScheduledConsoleExecutorInterface $consoleExecutor,
        private readonly float $consoleResultTimeoutSeconds = 300.0,
    ) {
        if (!is_finite($consoleResultTimeoutSeconds) || $consoleResultTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Console result timeout must be finite and positive.');
        }
    }

    public function __invoke(ExecuteScheduledJobCommand $command): void
    {
        $this->execute($command, true);
    }

    /** Normal return includes cancellation and recorded failure; it is not a success receipt. */
    public function executeOccurrence(SchedulerOccurrence $occurrence): void
    {
        $this->execute(new ExecuteScheduledJobCommand(
            $occurrence->jobId->toString(),
            $occurrence->jobType->value,
            $occurrence->command,
            $occurrence->parameters,
        ), false, $occurrence->origin === SchedulerOccurrenceOrigin::Manual);
    }

    private function execute(ExecuteScheduledJobCommand $command, bool $legacyLock, bool $manual = false): void
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($command->jobId));
        if ($job === null) {
            $this->logger->warning('Scheduled job not found', ['jobId' => $command->jobId]);

            return;
        }

        if (!$legacyLock && (
            (!$manual && $job->getStatus() !== ScheduleStatus::Active)
            || $job->getJobType()->value !== $command->jobType
            || $job->getCommand() !== $command->command
            || $job->getParameters() !== $command->parameters
            || json_encode($job->getParameters(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode($command->parameters, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
        )) {
            return;
        }

        // Validate command is still in the registry (may have been removed)
        $allowed = match ($command->jobType) {
            JobType::Messenger->value => $this->registry->isMessengerCommandAllowed($command->command),
            JobType::Console->value => $this->registry->isConsoleCommandAllowed($command->command),
            default => false,
        };

        if (!$allowed) {
            $this->logger->error('Scheduled job command not in registry — skipping', [
                'jobId' => $command->jobId,
                'jobType' => $command->jobType,
                'command' => $command->command,
            ]);

            $job->markFailed(sprintf('Command "%s" is not registered as schedulable.', $command->command));
            $this->scheduledJobService->save($job);
            if ($legacyLock) {
                $this->releaseLock($command->jobId);
            }

            return;
        }

        $job->markRunning();
        $this->scheduledJobService->save($job);

        $unknownCompletion = null;
        try {
            $result = match ($command->jobType) {
                JobType::Messenger->value => $this->dispatchMessenger($command),
                JobType::Console->value => $legacyLock
                    ? $this->dispatchConsole($command)
                    : $this->consoleExecutor->execute($command->command, $command->parameters),
            };

            $job->markSuccess($result);
        } catch (ScheduledConsoleCompletionUnknown $e) {
            // The child may still be running. Persist the uncertainty and stop
            // future cron dispatches; Messenger retries could duplicate its work.
            $job->markFailed($e->getMessage());
            if ($job->getStatus() === ScheduleStatus::Active) {
                $job->pause();
            }
            if (!$legacyLock) {
                $unknownCompletion = $e;
            }
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
        if ($legacyLock) {
            $this->releaseLock($command->jobId);
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

    /** @throws ScheduledConsoleCompletionUnknown When the CPU child completion cannot be confirmed. */
    private function dispatchConsole(ExecuteScheduledJobCommand $command): string
    {
        $payload = json_encode([
            'type' => 'scheduled_console',
            'command' => $command->command,
            'parameters' => $command->parameters,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        $key = sprintf('scheduled_console:%s:%s', $command->jobId, Uuid::generate()->toString());

        $this->cpuPool->dispatch($payload, $key);

        // Results are file-backed; the optional shared table is not a signal
        // that completion can be skipped. Use a monotonic deadline.
        $deadline = hrtime(true) / 1_000_000_000 + $this->consoleResultTimeoutSeconds;
        do {
            try {
                $row = $this->cpuPool->readResult($key);
            } catch (Throwable $e) {
                throw new ScheduledConsoleCompletionUnknown(
                    'Console completion unknown: result could not be read. Reconcile the child process before resuming the schedule.',
                    previous: $e,
                );
            }
            if ($row !== null) {
                if ($row['status'] === 'error') {
                    throw new \RuntimeException($row['data']);
                }
                if ($row['status'] !== 'ok') {
                    throw new \RuntimeException('Invalid console pool result status.');
                }
                $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !isset($data['success']) || !is_bool($data['success'])) {
                    throw new \RuntimeException('Invalid console command result.');
                }
                if (!$data['success']) {
                    throw new \RuntimeException(is_string($data['error'] ?? null) ? $data['error'] : 'Console command failed.');
                }
                $output = array_key_exists('output', $data) ? $data['output'] : 'ok';
                if (!is_string($output)) {
                    throw new \RuntimeException('Invalid console command output.');
                }

                return mb_substr($output, 0, 10000);
            }

            $remaining = $deadline - hrtime(true) / 1_000_000_000;
            if ($remaining > 0) {
                Async::sleep(min(0.1, $remaining));
            }
        } while ($remaining > 0);

        throw new ScheduledConsoleCompletionUnknown(
            'Console completion unknown: result wait timed out. Reconcile the child process before resuming the schedule.',
        );
    }

    private function releaseLock(string $jobId): void
    {
        $lockKey = sprintf('scheduler:lock:%s', $jobId);

        try {
            $this->redis->borrow(function (\Redis $redis) use ($lockKey): void {
                $redis->del($lockKey);
            });
        } catch (Throwable $e) {
            try {
                $this->logger->warning('Failed to release scheduler lock', [
                    'jobId' => $jobId,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // Diagnostics cannot retry an already persisted outcome.
            }
        }
    }
}
