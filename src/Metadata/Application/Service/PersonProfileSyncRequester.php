<?php

declare(strict_types=1);

namespace App\Metadata\Application\Service;

use App\Metadata\Application\Message\SyncPeopleMessage;
use App\Metadata\Application\Port\PersonProfileSyncRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

#[AsAlias(PersonProfileSyncRequestInterface::class)]
final readonly class PersonProfileSyncRequester implements PersonProfileSyncRequestInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {}

    public function request(): void
    {
        try {
            $this->bus->dispatch(new SyncPeopleMessage());
        } catch (Throwable $failure) {
            $this->logger->error('Could not queue Person profiles. The next metadata sync will retry them.', [
                'exception' => $failure,
            ]);
        }
    }
}
