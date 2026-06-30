<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Domain\Model\PublicId;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\TransportNameStamp;

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
            $jobId = new PublicId();
            $jobIdStamp = new JobIdStamp($jobId);
            $event->addStamps($jobIdStamp);
        }
        $jobId = $jobIdStamp->jobId;

        $message = $envelope->getMessage();
        $name = (new \ReflectionClass($message))->getShortName();

        $transportStamp = $envelope->last(TransportNameStamp::class);
        $queue = $transportStamp?->getTransportName();

        $this->jobMonitorService->create(
            jobId: $jobId->toString(),
            name: $name,
            queue: $queue,
        );

        $this->jobMonitorService->markStarted($jobId->toString());

        $serialized = $this->messageSerializer->serialize($envelope);
        if ($serialized !== null) {
            $this->jobMonitorService->setData(
                jobId: $jobId->toString(),
                data: $serialized,
                dataTruncated: false,
            );
        } else {
            $this->jobMonitorService->setData(
                jobId: $jobId->toString(),
                data: null,
                dataTruncated: true,
            );
        }
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $jobIdStamp = $envelope->last(JobIdStamp::class);

        if ($jobIdStamp === null) {
            return;
        }

        $this->jobMonitorService->markFinished($jobIdStamp->jobId->toString());
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $jobIdStamp = $envelope->last(JobIdStamp::class);

        if ($jobIdStamp === null) {
            return;
        }

        $this->jobMonitorService->markFailed(
            jobId: $jobIdStamp->jobId->toString(),
            exception: $event->getThrowable(),
        );
    }
}
