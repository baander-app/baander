<?php

declare(strict_types=1);

namespace App\Radio\Domain\Repository\RadioSource;

use App\Radio\Domain\Model\RadioSource\RadioSource;
use App\Shared\Domain\Model\Uuid;

interface RadioSourceRepositoryInterface
{
    public function find(Uuid $id): ?RadioSource;

    public function findAll(): array;

    public function findByType(string $type): array;

    public function findActive(): array;

    public function save(RadioSource $source): void;

    public function remove(RadioSource $source): void;
}
