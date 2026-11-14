<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Port;

use App\Lyrics\Application\DTO\LrclibRejected;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\DTO\LrclibUnavailable;

/**
 * Port interface for the LRCLIB API client.
 *
 * Defines the contract for fetching lyrics from LRCLIB.
 * Infrastructure implementations handle HTTP communication;
 * the application layer never depends on external API details.
 *
 * No method throws: a lookup returns null when LRCLIB has no record, LrclibRejected when
 * LRCLIB refused the request with a client error other than 404, 408 or 429, and
 * LrclibUnavailable when LRCLIB gave no usable answer, so callers can tell a miss, a refused
 * request and an outage apart. Signature lookups send the duration rounded to the nearest
 * second.
 */
interface LrclibClientInterface
{
    /**
     * Fetch lyrics by track signature using LRCLIB's cached (internal DB only) endpoint.
     *
     * Fast and predictable latency, but may miss lyrics that exist on external sources.
     */
    public function getBySignatureCached(
        string $trackName,
        string $artistName,
        string $albumName,
        float $duration,
    ): LrclibResult|LrclibRejected|LrclibUnavailable|null;

    /**
     * Fetch lyrics by track signature from LRCLIB's full endpoint, which also queries
     * external sources.
     */
    public function getBySignature(
        string $trackName,
        string $artistName,
        string $albumName,
        float $duration,
    ): LrclibResult|LrclibRejected|LrclibUnavailable|null;

    /**
     * Fetch a lyrics record by its LRCLIB ID.
     */
    public function getById(int $id): LrclibResult|LrclibRejected|LrclibUnavailable|null;

    /**
     * Search for lyrics records using keywords.
     *
     * @return list<LrclibSearchResult>|LrclibRejected|LrclibUnavailable an empty list when nothing matches
     */
    public function search(string $query): array|LrclibRejected|LrclibUnavailable;
}
