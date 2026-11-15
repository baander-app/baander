<?php

declare(strict_types=1);

namespace App\Metadata\Application\DTO\MusicBrainz;

/**
 * MusicBrainz merged the record of the requested ID into another one. It answers a lookup of a
 * merged ID with a permanent redirect to the surviving record's lookup, which is not followed:
 * the answer names the surviving ID instead, and the same lookup gets the same answer again.
 */
final readonly class MusicBrainzMerged
{
    /**
     * @param string $requestedId the ID that was looked up
     * @param string $survivingId the ID of the record MusicBrainz merged it into
     */
    public function __construct(
        public string $requestedId,
        public string $survivingId,
    ) {
    }
}
