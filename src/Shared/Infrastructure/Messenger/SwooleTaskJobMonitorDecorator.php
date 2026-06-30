<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\PublicId;
use Psr\Log\LoggerInterface;
use Swoole\Server;
use SwooleBundle\SwooleBundle\Server\TaskHandler\TaskHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Decorates the Swoole ServerTaskTransportHandler to track async jobs.
 *
 * Creates a job_monitor entry before bus->dispatch() runs, then marks
 * it finished or failed when the handler completes. Does not depend on
 * any bus middleware — the job ID is generated here.
 */
final readonly class SwooleTaskJobMonitorDecorator implements TaskHandler
{
    public function __construct(
        private TaskHandler $decorated,
        private JobMonitorService $jobMonitorService,
        private JobMessageSerializer $messageSerializer,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Server $server, Server\Task $task): void
    {
        $data = $task->data;

        if (!($data instanceof Envelope)) {
            $this->decorated->handle($server, $task);

            return;
        }

        // Only track messages that went through a transport (have ReceivedStamp)
        if ($data->last(ReceivedStamp::class) === null) {
            $this->decorated->handle($server, $task);

            return;
        }

        $message = $data->getMessage();
        $name = (new \ReflectionClass($message))->getShortName();
        $jobId = new PublicId();
        $jobIdStr = $jobId->toString();

        // Create + mark started
        $this->jobMonitorService->create(
            jobId: $jobIdStr,
            name: $name,
            queue: 'swoole_task',
        );

        $serialized = $this->messageSerializer->serialize($data);
        $this->jobMonitorService->setData(
            jobId: $jobIdStr,
            data: $serialized,
            dataTruncated: $serialized === null,
        );

        $this->jobMonitorService->markStarted($jobIdStr);

        $this->logger->info('Job started', [
            'job_id' => $jobIdStr,
            'job_type' => $message::class,
        ]);

        try {
            $this->decorated->handle($server, $task);

            $this->jobMonitorService->markFinished($jobIdStr);

            $this->logger->info('Job completed', [
                'job_id' => $jobIdStr,
                'job_type' => $message::class,
            ]);
        } catch (\Throwable $e) {
            try {
                $this->jobMonitorService->markFailed($jobIdStr, $e);
            } catch (\Throwable $monitorException) {
                $this->logger->error('Failed to mark job as failed in monitor', [
                    'job_id' => $jobIdStr,
                    'monitor_error' => $monitorException->getMessage(),
                ]);
            }

            $this->logger->error('Job failed', [
                'job_id' => $jobIdStr,
                'job_type' => $message::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
