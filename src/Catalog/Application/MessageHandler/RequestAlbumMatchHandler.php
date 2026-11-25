<?php

declare(strict_types=1);

namespace App\Catalog\Application\MessageHandler;

use App\Catalog\Application\Port\AlbumMatchRequestInterface;
use App\Metadata\Application\Message\SyncAlbumMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Turns an album sync an admin started, alone or through a library or genre sync, into an admin
 * request in the album match queue, whatever the automatic matching switch says. A match only
 * fills empty fields, so a forced sync asks for the same match as any other.
 */
final readonly class RequestAlbumMatchHandler
{
    public function __construct(
        private AlbumMatchRequestInterface $requests,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'async', sign: true)]
    public function __invoke(SyncAlbumMessage $message): void
    {
        $this->requests->requestForAdmin($message->albumId);
    }
}
