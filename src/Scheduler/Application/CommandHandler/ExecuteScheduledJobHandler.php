<?php

declare(strict_types=1);

namespace App\Scheduler\Application\CommandHandler;

use App\Scheduler\Application\Command\ExecuteScheduledJobCommand;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\Service\SchedulerRegistry;
use App\Scheduler\Domain\ValueObject\JobType;
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
        private readonly int $lockTtlSeconds = 3600,
    ) {
    }

    public function __invoke(ExecuteScheduledJobCommand $command): void
    {
        $job = $this->scheduledJobService->getById(Uuid::fromString($command->jobId));
        if ($job === null) {
            $this->logger->warning('Scheduled job not found', ['jobId' => $command->jobId]);

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
            $this->releaseLock($command->jobId);

            return;
        }

        $job->markRunning();
        $this->scheduledJobService->save($job);

        try {
            $result = match ($command->jobType) {
                JobType::Messenger->value => $this->dispatchMessenger($command),
                JobType::Console->value => $this->dispatchConsole($command),
            };

            $job->markSuccess($result);
        } catch (Throwable $e) {
            $this->logger->error('Scheduled job failed', [
                'jobId' => $command->jobId,
                'command' => $command->command,
                'error' => $e->getMessage(),
            ]);
            $job->markFailed($e->getMessage());
        }

        $this->scheduledJobService->save($job);
        $this->releaseLock($command->jobId);
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

    private function dispatchConsole(ExecuteScheduledJobCommand $command): string
    {
        $payload = json_encode([
            'type' => 'scheduled_console',
            'command' => $command->command,
            'parameters' => $command->parameters,
        ], JSON_THROW_ON_ERROR);

        $key = sprintf('scheduled_console:%s', $command->jobId);

        $this->cpuPool->dispatch($payload, $key);

        // Poll result table for completion (with timeout)
        $resultTable = $this->cpuPool->getResultTable();
        if ($resultTable === null) {
            return 'dispatched_to_pool';
        }

        $deadline = microtime(true) + 300.0; // 5 minute timeout
        while (microtime(true) < $deadline) {
            if ($resultTable->exists($key)) {
                $row = $resultTable->get($key);
                $resultTable->del($key);

                $data = json_decode($row['data'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);

                if (($data['success'] ?? false) === false) {
                    throw new \RuntimeException($data['error'] ?? 'Console command failed.');
                }

                return mb_substr($data['output'] ?? 'ok', 0, 10000); // Truncate to 10KB
            }

            Async::sleep(0.1);
        }

        return 'pool_timeout';
    }

    private function releaseLock(string $jobId): void
    {
        $lockKey = sprintf('scheduler:lock:%s', $jobId);

        try {
            $this->redis->borrow(function (\Redis $redis) use ($lockKey): void {
                $redis->del($lockKey);
            });
        } catch (Throwable $e) {
            $this->logger->warning('Failed to release scheduler lock', [
                'jobId' => $jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
