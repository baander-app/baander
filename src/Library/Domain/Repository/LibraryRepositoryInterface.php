<?php

declare(strict_types=1);

namespace App\Library\Domain\Repository;

use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;

interface LibraryRepositoryInterface
{
    public function save(Library $library): void;

    public function findByUuid(Uuid $uuid): ?Library;

    public function findBySlug(LibrarySlug $slug): ?Library;

    /**
     * @return Library[]
     */
    public function findByType(LibraryType $type): array;

    /**
     * @return Library[]
     */
    public function findAllOrdered(): array;

    /**
     * @return Library[]
     */
    public function findAccessibleByUser(Uuid $userId): array;

    public function delete(Library $library): void;
}
