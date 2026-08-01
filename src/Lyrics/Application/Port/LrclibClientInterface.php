<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Port;

use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;

/**
 * Port interface for the LRCLIB API client.
 *
 * Defines the contract for fetching lyrics from LRCLIB.
 * Infrastructure implementations handle HTTP communication;
 * the application layer never depends on external API details.
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
    ): ?LrclibResult;

    /**
     * Fetch lyrics by track signature with external source fallback.
     *
     * Tries the cached endpoint first. If no result (404), queries external sources
     * via the full /api/get endpoint.
     */
    public function getBySignature(
        string $trackName,
        string $artistName,
        string $albumName,
        float $duration,
    ): ?LrclibResult;

    /**
     * Fetch a lyrics record by its LRCLIB ID.
     */
    public function getById(int $id): ?LrclibResult;

    /**
     * Search for lyrics records using keywords.
     *
     * @return LrclibSearchResult[]
     */
    public function search(string $query): array;
}
