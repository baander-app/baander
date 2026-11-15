<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

/**
 * How long an LRCLIB call may wait before its request is sent: for the end of a pause LRCLIB
 * asked for, and for its turn under the pace of one request every two seconds that every
 * process shares. A call that cannot send within its wait answers unavailable.
 */
enum LrclibWait
{
    /** Someone waits on the answer: the per-song fetch, the search and the apply. */
    case Interactive;

    /** A fetch a worker handles from the queue. */
    case Queued;

    /** The longest wait, in seconds. */
    public function seconds(): float
    {
        return match ($this) {
            self::Interactive => 10.0,
            self::Queued => 30.0,
        };
    }
}
