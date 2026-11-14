<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;

/**
 * Starts the album match queue again when a match request is due and no chain of queue runs holds
 * the queue, as after a lost queue message. The initial schedule runs it every ten minutes.
 */
final readonly class RecoverAlbumMatchQueueCommand implements SchedulableCommandInterface
{
    public static function schedulerDescription(): string
    {
        return 'Start the MusicBrainz album match queue again when a match is due and no queue run is in flight.';
    }

    public static function schedulerParameters(): array
    {
        return [];
    }
}
