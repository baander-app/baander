<?php

declare(strict_types=1);

namespace App\Library\Application\Query;

use App\Shared\Domain\ValueObject\LibraryReadScope;

/** Content counts of one library the reader may see: GET /api/libraries/{id}/stats and `app:library:stats`. */
final readonly class GetLibraryStatsQuery
{
    public function __construct(
        /** The library's UUID or slug. */
        public string $library,
        /** The request's scope on the web; unrestricted from the shell. */
        public LibraryReadScope $scope,
    ) {
    }
}
