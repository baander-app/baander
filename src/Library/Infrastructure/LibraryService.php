<?php

declare(strict_types=1);

namespace App\Library\Infrastructure;

use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;

final class LibraryService implements LibraryPortInterface
{
    public function __construct(
        private readonly LibraryRepositoryInterface $libraryRepository,
    ) {
    }

    public function save(Library $library): void
    {
        $this->libraryRepository->save($library);
    }

    public function findByUuid(Uuid $uuid): ?Library
    {
        return $this->libraryRepository->findByUuid($uuid);
    }

    public function findBySlug(LibrarySlug $slug): ?Library
    {
        return $this->libraryRepository->findBySlug($slug);
    }

    /**
     * @return Library[]
     */
    public function findByType(LibraryType $type): array
    {
        return $this->libraryRepository->findByType($type);
    }

    /**
     * @return Library[]
     */
    public function findAllOrdered(): array
    {
        return $this->libraryRepository->findAllOrdered();
    }

    /**
     * @return Library[]
     */
    public function findAccessibleByUser(Uuid $userId): array
    {
        return $this->libraryRepository->findAccessibleByUser($userId);
    }

    public function delete(Library $library): void
    {
        $this->libraryRepository->delete($library);
    }
}
