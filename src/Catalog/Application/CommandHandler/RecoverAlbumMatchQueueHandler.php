<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler;

use App\Catalog\Application\Command\RecoverAlbumMatchQueueCommand;
use App\Catalog\Application\Message\DrainAlbumMatchesMessage;
use App\Catalog\Application\Service\MusicBrainzMatch\AlbumMatchQueueChain;
use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles RecoverAlbumMatchQueueCommand. The queue's runs queue each other, so a run lost to a
 * failed dispatch or a message that used up its retries would leave every request waiting: when a
 * request is due and no chain holds the queue, this queues a run that starts a new chain. A chain
 * that is only late gives way to the new one at its next run.
 *
 * A request a run holds is due only once that run's claim has lapsed. A run that died while it
 * matched an album held its chain as long as its claim, 15 minutes, so the recovery starts the
 * queue for that album at its first run after both have lapsed.
 */
final readonly class RecoverAlbumMatchQueueHandler
{
    public function __construct(
        private AlbumMatchStateRepositoryInterface $states,
        private AlbumMatchQueueChain $chains,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @return string what the run did, for the scheduled job's result */
    #[AsMessageHandler]
    public function __invoke(RecoverAlbumMatchQueueCommand $command): string
    {
        $nextDueAt = $this->states->nextRequestDueAt();
        if ($nextDueAt === null || $nextDueAt > $this->clock->now()) {
            return 'no match due';
        }
        if ($this->chains->isRunning()) {
            return 'queue running';
        }

        $this->logger->warning('A MusicBrainz match is due and no album match queue run is in flight; starting the queue again.');
        $this->bus->dispatch(new DrainAlbumMatchesMessage());

        return 'queue started';
    }
}
