<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/**
 * How long a MusicBrainz call may wait for its turn under the one-request-per-second limit
 * that every process shares.
 */
enum MusicBrainzSlotWait: string
{
    /** Seconds a bounded call waits for its turn before it gives up. */
    public const float BOUNDED_SECONDS = 10.0;

    /** A web request: it answers unavailable when its turn is more than ten seconds away. */
    case Bounded = 'bounded';

    /** Background work: it waits for its turn however long the queue ahead of it is. */
    case Unbounded = 'unbounded';

    /** The longest wait for a turn, in seconds; null when the wait has no limit. */
    public function maxSeconds(): ?float
    {
        return match ($this) {
            self::Bounded => self::BOUNDED_SECONDS,
            self::Unbounded => null,
        };
    }
}
