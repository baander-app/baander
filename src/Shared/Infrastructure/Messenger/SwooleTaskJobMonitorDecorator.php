<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\PublicId;
use Psr\Log\LoggerInterface;
use Swoole\Server;
use SwooleBundle\SwooleBundle\Server\TaskHandler\TaskHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Decorates the Swoole ServerTaskTransportHandler to track async jobs.
 *
 * Starts an attempt of the message's job before bus->dispatch() runs, then
 * marks that attempt finished or failed when the handler completes; a job that
 * stopped at a cancellation checkpoint keeps its `cancelled` row. The job ID
 * comes from the message (JobMonitoringMiddleware assigns it at dispatch), so a
 * repeated task delivery restarts the same job_monitors row; a message without
 * one gets an ID here.
 */
final readonly class SwooleTaskJobMonitorDecorator implements TaskHandler
{
    public function __construct(
        private TaskHandler $decorated,
        private JobMonitorService $jobMonitorService,
        private JobMessageSerializer $messageSerializer,
        private LoggerInterface $logger,
        private SerializerInterface $transportSerializer,
    ) {
    }

    public function handle(Server $server, Server\Task $task): void
    {
        $data = $task->data;
        if (is_array($data)) {
            $data = $this->transportSerializer->decode($data)->with(new ReceivedStamp('swoole_task'));
        }

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
        $jobIdStamp = $data->last(JobIdStamp::class);
        $jobId = $jobIdStamp !== null ? $jobIdStamp->jobId : new PublicId();
        $jobIdStr = $jobId->toString();

        $serialized = $this->messageSerializer->serialize($data);
        $attempt = $this->jobMonitorService->startAttempt(
            jobId: $jobIdStr,
            name: $name,
            queue: 'swoole_task',
            data: $serialized,
            dataTruncated: $serialized === null,
        );

        $this->logger->info('Job started', [
            'job_id' => $jobIdStr,
            'job_type' => $message::class,
        ]);

        try {
            $this->decorated->handle($server, $task);

            // The inner handler does not return the bus's envelope, so its JobCancelledStamp is
            // out of reach; a job JobCancellationMiddleware cancelled (and logged) is no longer
            // running, and neither is an attempt a later delivery took over.
            if ($this->jobMonitorService->markFinished($jobIdStr, $attempt)) {
                $this->logger->info('Job completed', [
                    'job_id' => $jobIdStr,
                    'job_type' => $message::class,
                ]);
            } else {
                $this->logger->info('Job ended without finishing its attempt: it was cancelled or superseded', [
                    'job_id' => $jobIdStr,
                    'job_type' => $message::class,
                    'attempt' => $attempt,
                ]);
            }
        } catch (\Throwable $e) {
            try {
                $this->jobMonitorService->markFailed($jobIdStr, $attempt, $e);
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
