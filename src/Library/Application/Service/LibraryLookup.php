<?php

declare(strict_types=1);

namespace App\Library\Application\Service;

use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;

/** Finds a library by public ID or slug, as the admin API and the `app:library:*` commands name it. */
final readonly class LibraryLookup
{
    public function __construct(
        private LibraryRepositoryInterface $libraries,
    ) {
    }

    /**
     * @param LibraryReadScope $scope the libraries the caller may see; a caller that may see every
     *                                library passes the unrestricted scope and says why
     *
     * @throws LibraryNotFoundException when no visible library has the public ID or slug
     */
    public function byIdentifier(string $identifier, LibraryReadScope $scope): Library
    {
        return $this->find($identifier, $scope) ?? throw LibraryNotFoundException::forIdentifier($identifier);
    }

    private function find(string $identifier, LibraryReadScope $scope): ?Library
    {
        try {
            $library = $this->libraries->findVisibleByPublicId(PublicId::fromString($identifier), $scope);
            if ($library !== null) {
                return $library;
            }
        } catch (\InvalidArgumentException) {
            // Not a public ID; try it as a slug.
        }

        try {
            $slug = new LibrarySlug($identifier);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->libraries->findVisibleBySlug($slug, $scope);
    }
}
