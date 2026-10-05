<?php

declare(strict_types=1);

// Copied into src/ only inside the disposable worker-command checkout so normal
// production service discovery can load this test listener without vendor edits.
namespace App\Shared\Infrastructure\Worker;

use App\Scheduler\Application\Command\ExecuteScheduledOccurrenceCommand;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisReceivedStamp;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

#[AsEventListener(event: WorkerMessageHandledEvent::class, priority: -4096)]
final readonly class ConsumerAckFixture
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(WorkerMessageHandledEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ExecuteScheduledOccurrenceCommand) {
            return;
        }
        $stamp = $event->getEnvelope()->last(RedisReceivedStamp::class);
        if (!$stamp instanceof RedisReceivedStamp) {
            throw new \RuntimeException('Consumer ACK fixture requires an actual received Redis entry.');
        }
        // This independent autocommit marker is reached only after the real
        // handler returns; Worker dispatches this event before receiver->ack().
        $armed = $this->connection->fetchOne(<<<'SQL'
            UPDATE worker_command_consumer_ack_gate
            SET occurrence_id = :occurrence, message_id = :message, arrivals = arrivals + 1
            WHERE id = true
            RETURNING armed
            SQL, ['occurrence' => $message->occurrenceId->toString(), 'message' => $stamp->getId()]);
        if ($armed === true) {
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(87365024)')->free();
        }
    }
}
