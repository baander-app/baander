<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

/**
 * LRCLIB gave no usable answer: the request failed or timed out, LRCLIB answered 408, 429,
 * a redirect or a server error, or its response could not be read.
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
