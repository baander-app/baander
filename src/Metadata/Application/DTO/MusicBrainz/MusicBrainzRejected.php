<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/**
 * MusicBrainz or the Cover Art Archive refused the request with a client error other than
 * 408 or 429, such as 400 for an ID it does not accept.
 *
 * The lookup returns it beside MusicBrainzUnavailable so a caller can tell a request the
 * service will not answer from an outage. Sending the same request again gets the same answer.
 */
final readonly class MusicBrainzRejected
{
    /**
     * @param int $status the HTTP status the service answered with
     */
    public function __construct(
        public int $status,
    ) {
    }
}
