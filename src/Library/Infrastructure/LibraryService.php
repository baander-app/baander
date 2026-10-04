<?php

declare(strict_types=1);

namespace App\Library\Infrastructure;

use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final class LibraryService implements LibraryPortInterface
{
    public function __construct(
        private readonly LibraryRepositoryInterface $libraryRepository,
    ) {
    }

    public function findVisible(LibraryReadScope $scope, ?LibraryType $type = null): array
    {
        return $this->libraryRepository->findVisible($scope, $type);
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Library
    {
        return $this->libraryRepository->findVisibleByUuid($uuid, $scope);
    }

    public function findVisibleBySlug(LibrarySlug $slug, LibraryReadScope $scope): ?Library
    {
        return $this->libraryRepository->findVisibleBySlug($slug, $scope);
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
