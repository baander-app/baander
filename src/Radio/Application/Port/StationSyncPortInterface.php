<?php

declare(strict_types=1);

namespace App\Radio\Application\Port;

/**
 * Contract for station sync adapters (IPRD, TuneIn, etc.).
 */
interface StationSyncPortInterface
{
    /**
     * @return list<array{name: string, code: string, station_count: int}>
     */
    public function fetchCountries(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchStationsByCountry(string $countryCode): array;
}
