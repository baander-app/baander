<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Lets another context ask for lyrics of newly ingested songs.
 *
 * Queues one asynchronous fetch per song while `lyrics.auto_fetch` is on and
 * does nothing while it is off. The toggle is read on every call. Call it only
 * after the songs are committed, so the fetch can find them.
 */
interface LyricsFetchRequestInterface
{
    public function requestFetch(Uuid ...$songIds): void;
}
