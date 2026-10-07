<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\PublicId;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Records each worker delivery as an attempt of the message's job.
 *
 * The job ID travels with the message, so a Messenger retry and a retry from the failure
 * transport restart the job's existing row instead of adding one; the row keeps the queue
 * it was first received from. A message without a job ID gets one here.
 */
final class WorkerJobMonitorSubscriber
{
    public function __construct(
        private readonly JobMonitorService $jobMonitorService,
        private readonly JobMessageSerializer $messageSerializer,
    ) {
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();

        $jobIdStamp = $envelope->last(JobIdStamp::class);
        if ($jobIdStamp === null) {
            $jobIdStamp = new JobIdStamp(new PublicId());
            $event->addStamps($jobIdStamp);
        }

        $serialized = $this->messageSerializer->serialize($envelope);
        $attempt = $this->jobMonitorService->startAttempt(
            jobId: $jobIdStamp->jobId->toString(),
            name: (new \ReflectionClass($envelope->getMessage()))->getShortName(),
            queue: $event->getReceiverName(),
            data: $serialized,
            dataTruncated: $serialized === null,
        );
        $event->addStamps(new JobAttemptStamp($attempt));
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $attempt = $this->attempt($event->getEnvelope());
        if ($attempt !== null) {
            $this->jobMonitorService->markFinished($attempt[0], $attempt[1]);
        }
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $attempt = $this->attempt($event->getEnvelope());
        if ($attempt !== null) {
            $this->jobMonitorService->markFailed($attempt[0], $attempt[1], $event->getThrowable());
        }
    }

    /**
     * The attempt this delivery started, or null when it started none.
     *
     * @return array{string, int}|null
     */
    private function attempt(Envelope $envelope): ?array
    {
        $jobIdStamp = $envelope->last(JobIdStamp::class);
        $attemptStamp = $envelope->last(JobAttemptStamp::class);

        return $jobIdStamp === null || $attemptStamp === null
            ? null
            : [$jobIdStamp->jobId->toString(), $attemptStamp->attempt];
    }
}
