<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use App\Shared\Domain\Event\DomainEventInterface;
use App\Shared\Domain\Event\Outbox\OutboxEventDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** Commits notification projections and delivery intents once per persisted event. */
final readonly class OutboxEventDispatcher implements OutboxEventDispatcherInterface
{
    public function __construct(private ManagerRegistry $doctrine, private EventDispatcherInterface $dispatcher)
    {
    }

    public function dispatch(DomainEventInterface $event, int $outboxId): void
    {
        if ($outboxId < 1) {
            throw new \InvalidArgumentException('Outbox identity must be positive.');
        }
        $manager = $this->doctrine->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Outbox notification replay requires the ORM entity manager.');
        }
        $connection = $manager->getConnection();
        try {
            $connection->transactional(function () use ($connection, $manager, $event, $outboxId): void {
                $claimed = $connection->executeStatement(
                    'INSERT INTO domain_event_outbox_receipt (outbox_id, consumer) VALUES (:id, :consumer) ON CONFLICT DO NOTHING',
                    ['id' => $outboxId, 'consumer' => 'notifications.v1'],
                );
                if ($claimed === 0) {
                    return;
                }
                // Only durable consumers are wired to this private dispatcher.
                $this->dispatcher->dispatch($event);
                $manager->flush();
            });
        } catch (\Throwable $error) {
            // DBAL rollback does not undo Doctrine's in-memory unit of work.
            if ($manager->isOpen()) {
                $manager->clear();
            } else {
                $this->doctrine->resetManager();
            }
            throw $error;
        }
    }
}
