<?php

declare(strict_types=1);

namespace App\Metadata\Application\Message;

/**
 * The part of an administrator's metadata sync that queues a SyncArtistMessage for every artist
 * with a MusicBrainz ID and missing details. Catalog handles it.
 */
final readonly class SyncArtistsMessage
{
}
