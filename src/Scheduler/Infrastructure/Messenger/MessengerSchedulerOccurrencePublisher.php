<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Messenger;

use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use App\Scheduler\Application\Port\SchedulerOccurrencePublisherInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/** Explicit async sender: a missing bus route must never execute a job in the relay. */
final readonly class MessengerSchedulerOccurrencePublisher implements SchedulerOccurrencePublisherInterface
{
    public function __construct(private SenderInterface $sender) {}

    public function publish(Uuid $occurrenceId): void
    {
        $this->sender->send(new Envelope(new ExecuteScheduledOccurrenceCommand($occurrenceId)));
    }
}
