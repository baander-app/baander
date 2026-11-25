<?php

declare(strict_types=1);

namespace App\Radio\Application\Port;

use App\Radio\Application\DTO\RadioStationPage;
use App\Shared\Domain\Model\PublicId;
use App\Radio\Domain\Model\RadioStation\RadioStation;
use App\Shared\Domain\Model\Uuid;

interface RadioStationPortInterface
{
    /** Stations per page of the station list unless the caller names a limit. */
    public const int DEFAULT_LIMIT = 50;

    /** The most stations one page of the station list holds. */
    public const int MAX_LIMIT = 500;

    public function findByPublicId(PublicId $publicId): ?RadioStation;

    /**
     * A page of the stations that match every given filter, by name and then id. The
     * search text matches part of the name, case-insensitively and literally.
     *
     * @param list<PublicId> $publicIds only these stations; an empty list does not filter
     */
    public function listStations(?string $countryCode, ?string $query, array $publicIds, int $limit, int $offset): RadioStationPage;

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
