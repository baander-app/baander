<?php

declare(strict_types=1);

namespace App\Library\Application\Service;

use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

/** Finds a library by UUID or slug, as the admin API and the `app:library:*` commands name it. */
final readonly class LibraryLookup
{
    public function __construct(
        private LibraryRepositoryInterface $libraries,
    ) {
    }

    /**
     * @param LibraryReadScope|null $scope the libraries the caller may see; null for every library
     *
     * @throws LibraryNotFoundException when no visible library has the UUID or slug
     */
    public function byIdentifier(string $identifier, ?LibraryReadScope $scope = null): Library
    {
        $scope ??= LibraryReadScope::unrestricted();

        return $this->find($identifier, $scope) ?? throw LibraryNotFoundException::forIdentifier($identifier);
    }

    private function find(string $identifier, LibraryReadScope $scope): ?Library
    {
        try {
            $library = $this->libraries->findVisibleByUuid(Uuid::fromString($identifier), $scope);
            if ($library !== null) {
                return $library;
            }
        } catch (\InvalidArgumentException) {
            // Not a UUID; try it as a slug.
        }

        try {
            $slug = new LibrarySlug($identifier);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->libraries->findVisibleBySlug($slug, $scope);
    }
}
