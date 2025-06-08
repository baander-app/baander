<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Illuminate\Queue\Events\{JobExceptionOccurred, JobFailed, JobProcessed, JobProcessing, JobQueued};
use Illuminate\Support\Facades\App;
use Laravel\Octane\Facades\Octane;
use Psr\Log\LoggerInterface;
use Swoole\Http\Server;

class QueueListener
{

    public function __construct(
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * Handle job-queued event
     */
    public function handleJobQueued(JobQueued $event): void
    {
        if (!config('apm.monitoring.queue', true)) {
            return;
        }

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                "Queue {$this->getJobName($event->job)}",
                'messaging',
                'queue',
                'send',
            );

            if ($span) {
                $this->addQueuedSpanContext($manager, $span, $event);
                $span->end();
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to create queue queued span', [
                'exception' => $e->getMessage(),
                'job'       => $this->getJobName($event->job),
            ]);
        }
    }

    /**
     * Get job name from job object
     */
    private function getJobName($job): string
    {
        if (method_exists($job, 'resolveName')) {
            return $job->resolveName();
        }

        if (method_exists($job, 'getName')) {
            return $job->getName();
        }

        if (isset($job->job)) {
            return $job->job;
        }

        return get_class($job);
    }

    /**
     * Add context to queued job span
     */
    private function addQueuedSpanContext(OctaneApmManager $manager, $span, JobQueued $event): void
    {
        $context = [
            'queue' => [
                'job_name'     => $this->getJobName($event->job),
                'queue_name'   => $event->job->getQueue(),
                'connection'   => $event->connectionName,
                'payload_size' => strlen(json_encode($event->job->payload())),
                'delay'        => $event->job->getDelay(),
            ],
        ];

        $manager->setSpanContext($span, $context);
        $manager->addSpanTag($span, 'queue.name', $event->job->getQueue());
        $manager->addSpanTag($span, 'queue.connection', $event->connectionName);
    }

    /**
     * Handle job processing event
     */
    public function handleJobProcessing(JobProcessing $event): void
    {
        if (!config('apm.monitoring.queue', true)) {
            return;
        }

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            // Create a transaction for the job processing
            $transaction = $manager->beginTransaction(
                $this->getJobName($event->job),
                'job',
            );

            if ($transaction) {
                $this->addProcessingTransactionContext($manager, $transaction, $event);

                // Store transaction data in Swoole table
                $this->storeTransactionData($event->job->getJobId(), [
                    'start_time' => microtime(true),
                    'job_name'   => $this->getJobName($event->job),
                    'queue_name' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to create queue processing transaction', [
                'exception' => $e->getMessage(),
                'job'       => $this->getJobName($event->job),
            ]);
        }
    }

    /**
     * Add context to processing job transaction
     */
    private function addProcessingTransactionContext(OctaneApmManager $manager, $transaction, JobProcessing $event): void
    {
        $context = [
            'queue' => [
                'job_name'   => $this->getJobName($event->job),
                'job_id'     => $event->job->getJobId(),
                'queue_name' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempts'   => $event->job->attempts(),
                'max_tries'  => $event->job->maxTries(),
                'timeout'    => $event->job->timeout(),
            ],
        ];

        // Add context to the current transaction (which is the one we just created)
        $manager->addCustomContext($context);
        $manager->addCustomTag('queue.name', $event->job->getQueue());
        $manager->addCustomTag('queue.connection', $event->connectionName);
        $manager->addCustomTag('queue.attempts', (string)$event->job->attempts());
    }

    /**
     * Store transaction data
     */
    private function storeTransactionData(string $jobId, array $data): void
    {
        if (!app()->bound(Server::class)) {
            \Cache::set($jobId, [
                'start_time' => $data['start_time'],
                'job_name'   => $data['job_name'] ?? '',
                'queue_name' => $data['queue_name'] ?? '',
                'connection' => $data['connection'] ?? '',
            ]);
        }

        Octane::table('apm_queue_transactions')->set($jobId, [
            'start_time' => $data['start_time'],
            'job_name'   => $data['job_name'] ?? '',
            'queue_name' => $data['queue_name'] ?? '',
            'connection' => $data['connection'] ?? '',
        ]);
    }

    /**
     * Handle job processed event
     */
    public function handleJobProcessed(JobProcessed $event): void
    {
        if (!config('apm.monitoring.queue', true)) {
            return;
        }

        $jobId = $event->job->getJobId();
        $transactionData = $this->getTransactionData($jobId);

        if ($transactionData) {
            try {
                /** @var OctaneApmManager $manager */
                $manager = App::make(OctaneApmManager::class);

                // Get current transaction (should be the job transaction)
                $duration = (microtime(true) - $transactionData['start_time']) * 1000; // Convert to ms

                // Add completion context to current transaction
                $manager->addCustomTag('queue.outcome', 'success');
                $manager->addCustomTag('queue.duration_ms', round($duration, 2));

                // The transaction will automatically end when the job completes
                // We just need to clean up our tracking data
                $this->removeTransactionData($jobId);
            } catch (\Throwable $e) {
                $this->logger?->error('Failed to update queue processing transaction', [
                    'exception' => $e->getMessage(),
                    'job_id'    => $jobId,
                ]);
            }
        }
    }

    /**
     * Get transaction data
     */
    private function getTransactionData(string $jobId): ?array
    {
        if (!app()->bound(Server::class)) {
            return \Cache::get($jobId);
        }

        return Octane::table('apm_queue_transactions')->get($jobId);
    }

    /**
     * Remove transaction data
     */
    private function removeTransactionData(string $jobId): void
    {
        if (!app()->bound(Server::class)) {
            \Cache::forget($jobId);
        }

        Octane::table('apm_queue_transactions')->del($jobId);
    }

    /**
     * Handle job failed event
     */
    public function handleJobFailed(JobFailed $event): void
    {
        if (!config('apm.monitoring.queue', true)) {
            return;
        }

        $jobId = $event->job->getJobId();
        $transactionData = $this->getTransactionData($jobId);

        if ($transactionData) {
            try {
                /** @var OctaneApmManager $manager */
                $manager = App::make(OctaneApmManager::class);

                $duration = (microtime(true) - $transactionData['start_time']) * 1000;

                // Add failure context to current transaction
                $manager->addCustomTag('queue.outcome', 'failure');
                $manager->addCustomTag('queue.duration_ms', round($duration, 2));
                $manager->addCustomTag('queue.error.message', $event->exception->getMessage());
                $manager->addCustomTag('queue.error.type', get_class($event->exception));

                // The transaction will automatically end when the job completes
                // We just need to clean up our tracking data
                $this->removeTransactionData($jobId);
            } catch (\Throwable $e) {
                $this->logger?->error('Failed to update failed queue transaction', [
                    'exception' => $e->getMessage(),
                    'job_id'    => $jobId,
                ]);
            }
        }
    }

    /**
     * Handle job exception occurred event
     */
    public function handleJobExceptionOccurred(JobExceptionOccurred $event): void
    {
        if (!config('apm.monitoring.queue', true)) {
            return;
        }

        try {
            /** @var OctaneApmManager $manager */
            $manager = App::make(OctaneApmManager::class);

            $span = $manager->createSpan(
                "Exception in {$this->getJobName($event->job)}",
                'messaging',
                'queue',
                'process',
            );

            if ($span) {
                $this->addExceptionSpanContext($manager, $span, $event);
                $span->end();
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to create queue exception span', [
                'exception' => $e->getMessage(),
                'job'       => $this->getJobName($event->job),
            ]);
        }
    }

    /**
     * Add context to exception span
     */
    private function addExceptionSpanContext(OctaneApmManager $manager, $span, JobExceptionOccurred $event): void
    {
        $context = [
            'queue' => [
                'job_name'   => $this->getJobName($event->job),
                'job_id'     => $event->job->getJobId(),
                'queue_name' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempts'   => $event->job->attempts(),
                'error'      => [
                    'message' => $event->exception->getMessage(),
                    'type'    => get_class($event->exception),
                    'file'    => $event->exception->getFile(),
                    'line'    => $event->exception->getLine(),
                ],
            ],
        ];

        $manager->setSpanContext($span, $context);
        $manager->addSpanTag($span, 'queue.name', $event->job->getQueue());
        $manager->addSpanTag($span, 'queue.connection', $event->connectionName);
        $manager->addSpanTag($span, 'error', 'true');
    }
}