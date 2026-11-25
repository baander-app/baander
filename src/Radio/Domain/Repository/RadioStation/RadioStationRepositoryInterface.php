<?php

declare(strict_types=1);

namespace App\Radio\Domain\Repository\RadioStation;

use App\Radio\Domain\Model\RadioStation\RadioStation;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface RadioStationRepositoryInterface
{
    public function find(Uuid $id): ?RadioStation;

    public function findByPublicId(PublicId $publicId): ?RadioStation;

    public function findBySourceAndExternalId(Uuid $sourceId, string $externalId): ?RadioStation;

    /** @return list<RadioStation> */
    public function findBySourceAndCountry(Uuid $sourceId, string $countryCode): array;

    /**
     * A page of the stations that match every given filter, by name and then id. The
     * search text matches part of the name, case-insensitively and literally.
     *
     * @param list<PublicId> $publicIds only these stations; an empty list does not filter
     *
     * @return list<RadioStation>
     */
    public function findMatching(?string $countryCode, ?string $query, array $publicIds, int $limit, int $offset): array;

    /** @param list<PublicId> $publicIds */
    public function countMatching(?string $countryCode, ?string $query, array $publicIds): int;

    public function save(RadioStation $station): void;

    public function remove(RadioStation $station): void;
}
