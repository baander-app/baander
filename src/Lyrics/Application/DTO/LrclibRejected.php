<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

/**
 * LRCLIB refused the request with a client error other than 404, 408 or 429, such as 400
 * for a duration it does not accept.
 *
 * The client returns it beside LrclibUnavailable so a caller can tell a request LRCLIB
 * will not answer from an outage. Sending the same request again gets the same answer.
 */
final readonly class LrclibRejected
{
    /**
     * @param int $status the HTTP status LRCLIB answered with
     */
    public function __construct(
        public int $status,
    ) {
    }
}
