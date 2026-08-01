<?php

declare(strict_types=1);

namespace App\Radio\Domain\Repository\RadioStation;

use App\Radio\Domain\Model\RadioStation\RadioStation;
use App\Shared\Domain\Model\Uuid;

interface RadioStationRepositoryInterface
{
    public function find(Uuid $id): ?RadioStation;

    public function findBySourceAndExternalId(Uuid $sourceId, string $externalId): ?RadioStation;

    public function findByCountry(string $countryCode): array;

    public function findBySourceAndCountry(Uuid $sourceId, string $countryCode): array;

    public function search(string $query, ?string $countryCode = null): array;

    public function save(RadioStation $station): void;

    public function remove(RadioStation $station): void;
}
