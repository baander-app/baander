<?php

declare(strict_types=1);

namespace App\Library\Application\Query;

use App\Shared\Domain\ValueObject\LibraryReadScope;

/** One library the reader may see: GET /api/libraries/{id} and `app:library:show`. */
final readonly class GetLibraryQuery
{
    public function __construct(
        /** The library's UUID or slug. */
        public string $library,
        /** The request's scope on the web; unrestricted from the shell. */
        public LibraryReadScope $scope,
    ) {
    }
}
