<?php

declare(strict_types=1);

namespace App\Radio\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Radio\Domain\Model\RadioStation\RadioStation;
use App\Shared\Domain\Model\Uuid;

interface RadioStationPortInterface
{
    public function findByPublicId(PublicId $publicId): ?RadioStation;

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
