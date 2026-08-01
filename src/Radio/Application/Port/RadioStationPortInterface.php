<?php

declare(strict_types=1);

namespace App\Radio\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface RadioStationPortInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listStations(?string $countryCode = null, ?string $query = null): array;

    /**
     * @return array<string, mixed>
     */
    public function getStation(Uuid $stationId): array;

    /**
     * Sync stations for a country from a source.
     *
     * @return int Number of stations synced
     */
    public function syncCountryStations(Uuid $sourceId, string $countryCode): int;
}
