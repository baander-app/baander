<?php

declare(strict_types=1);

namespace App\Library\Infrastructure\Messaging;

use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Event\LibraryDirectoryIndexed;
use App\Shared\Domain\Event\DomainEventInterface;
use App\Shared\Domain\Event\Outbox\OutboxEventDispatcherInterface;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final readonly class LibraryDirectoryOutboxEventDispatcher implements OutboxEventDispatcherInterface
{
    public function __construct(
        private OutboxEventDispatcherInterface $inner,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function dispatch(DomainEventInterface $event, int $outboxId): void
    {
        if (!$event instanceof LibraryDirectoryIndexed) {
            $this->inner->dispatch($event, $outboxId);
            return;
        }

        $files = array_map(static fn (array $file): DiscoveredFile => new DiscoveredFile(
            $file['absolute_path'],
            $file['relative_path'],
            $file['extension'],
            $file['size'],
            $file['modified_at'],
            $file['hash'],
        ), $event->files);

        $this->messageBus->dispatch(new FilesDiscovered(
            Uuid::fromString($event->libraryId),
            $event->libraryType,
            $event->directory,
            $files,
        ), [new TransportNamesStamp(['async'])]);
    }
}
