<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/**
 * MusicBrainz or the Cover Art Archive gave no usable answer: no turn under the shared rate
 * limit came within the wait, the request failed or timed out, the service answered 408, 429,
 * a redirect it may not follow or a server error (MusicBrainz answers 503 when its own rate
 * limit is exceeded), or the response could not be read.
 *
 * The lookup returns it where "nothing found" is null or an empty list, so a caller can tell
 * an outage from a miss. A retry may succeed.
 */
final readonly class MusicBrainzUnavailable
{
    /**
     * @param string $reason what went wrong, for the log, such as "HTTP 503"
     */
    public function __construct(
        public string $reason,
    ) {
    }
}
