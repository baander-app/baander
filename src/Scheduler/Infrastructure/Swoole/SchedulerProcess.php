<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Swoole;

use App\Scheduler\Application\Command\ExecuteScheduledJobCommand;
use App\Scheduler\Application\Port\ScheduledJobPortInterface;
use App\Scheduler\Domain\ValueObject\ScheduleStatus;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\Async;
use Psr\Log\LoggerInterface;
use Swoole\Process;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Standalone scheduler process that runs a single timer to evaluate cron
 * expressions and dispatch due jobs via Messenger.
 *
 * Spawns one Swoole\Process (not per-worker) to avoid N× dispatch when
 * running multiple HTTP workers. Uses Redis locks per job to prevent
 * double-dispatch across process boundaries or after restarts.
 */
final class SchedulerProcess implements Bootable
{
    private ?Process $process = null;
    private bool $booted = false;

    public function __construct(
        private readonly ScheduledJobPortInterface $scheduledJobService,
        private readonly MessageBusInterface $messageBus,
        private readonly RedisClientFactory $redis,
        private readonly LoggerInterface $logger,
        private readonly int $lockTtlSeconds = 3600,
    ) {
    }

    public function boot(array $runtimeConfiguration = []): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $service = $this->scheduledJobService;
        $bus = $this->messageBus;
        $redisFactory = $this->redis;
        $logger = $this->logger;
        $lockTtl = $this->lockTtlSeconds;

        $this->process = new Process(function (Process $worker) use ($service, $bus, $redisFactory, $logger, $lockTtl): void {
            $worker->name('scheduler-process');

            $timerId = \Swoole\Timer::tick(60_000, function () use ($service, $bus, $redisFactory, $logger, $lockTtl): void {
                $this->tick($service, $bus, $redisFactory, $logger, $lockTtl);
            });

            $logger->info('Scheduler process started', ['pid' => $worker->pid]);

            // Keep process alive — read blocks until parent sends empty string (shutdown signal)
            while (true) {
                $data = $worker->read();
                if ($data === '' || $data === false) {
                    \Swoole\Timer::clear($timerId);
                    $logger->info('Scheduler process shutting down', ['pid' => $worker->pid]);
                    break;
                }
            }
        }, false, SWOOLE_IPC_UNIXSOCK);

        $this->process->start();

        $this->logger->info('Scheduler process spawned', ['pid' => $this->process->pid]);
    }

    public function shutdown(): void
    {
        if ($this->process !== null && $this->process->pid > 0) {
            try {
                $this->process->write(''); // Signal shutdown
            } catch (Throwable) {
                // Process may have already exited
            }

            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline && @posix_kill($this->process->pid, 0)) {
                Async::sleep(0.05);
            }

            if (@posix_kill($this->process->pid, 0)) {
                @posix_kill($this->process->pid, SIGKILL);
            }

            $this->logger->info('Scheduler process shut down');
        }
    }

    /**
     * Single tick: find all active jobs, acquire Redis lock per job, dispatch.
     */
    public function tick(
        ScheduledJobPortInterface $service,
        MessageBusInterface $bus,
        RedisClientFactory $redisFactory,
        LoggerInterface $logger,
        int $lockTtl,
    ): void {
        $now = new \DateTimeImmutable();

        try {
            $activeJobs = $service->findByStatus(ScheduleStatus::Active);
        } catch (Throwable $e) {
            $logger->error('Scheduler tick failed to load jobs', ['error' => $e->getMessage()]);

            return;
        }

        $dispatched = 0;
        $skipped = 0;

        foreach ($activeJobs as $job) {
            if (!$job->isDue($now)) {
                continue;
            }

            $lockKey = sprintf('scheduler:lock:%s', $job->getId()->toString());

            try {
                $acquired = $redisFactory->borrow(function (\Redis $redis) use ($lockKey, $lockTtl): bool {
                    return (bool) $redis->set($lockKey, (string) getmypid(), ['NX', 'EX' => $lockTtl]);
                });
            } catch (Throwable $e) {
                $logger->warning('Scheduler lock acquisition failed', [
                    'jobId' => $job->getId()->toString(),
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
                continue;
            }

            if (!$acquired) {
                $skipped++;
                continue;
            }

            $bus->dispatch(new ExecuteScheduledJobCommand(
                jobId: $job->getId()->toString(),
                jobType: $job->getJobType()->value,
                command: $job->getCommand(),
                parameters: $job->getParameters(),
            ));

            $dispatched++;
        }

        if ($dispatched > 0 || $skipped > 0) {
            $logger->info('Scheduler tick completed', [
                'dispatched' => $dispatched,
                'skipped' => $skipped,
                'time' => $now->format(\DateTimeInterface::ATOM),
            ]);
        }
    }
}
