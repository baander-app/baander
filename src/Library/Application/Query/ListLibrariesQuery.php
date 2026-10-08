<?php

declare(strict_types=1);

namespace App\Library\Application\Query;

use App\Shared\Domain\ValueObject\LibraryReadScope;

/** The libraries the reader may see, in display order: GET /api/libraries and `app:library:list`. */
final readonly class ListLibrariesQuery
{
    public function __construct(
        /** The request's scope on the web; unrestricted from the shell. */
        public LibraryReadScope $scope,
        /** Only libraries of this LibraryType value; null for every type. */
        public ?string $type = null,
    ) {
    }
}
