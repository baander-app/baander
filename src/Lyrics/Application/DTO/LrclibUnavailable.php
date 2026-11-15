<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

/**
 * LRCLIB gave no usable answer: the request could not be sent within the caller's wait, it
 * failed or timed out, LRCLIB answered 408, a redirect or a server error, still answered that
 * requests come too fast after the request was sent again, or its response could not be read.
 *
 * The client returns it where "nothing found" is null or an empty list, so a caller can
 * tell an outage from a miss. A retry may succeed.
 */
final readonly class LrclibUnavailable
{
    /**
     * @param string $reason what went wrong, for the log, such as "HTTP 503"
     */
    public function __construct(
        public string $reason,
    ) {
    }
}
