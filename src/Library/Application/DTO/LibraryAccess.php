<?php

declare(strict_types=1);

namespace App\Library\Application\DTO;

use App\Library\Domain\Model\Library;

/** One library and whether a user may see it, as the admin user page and `app:library:member:*` show it. */
final readonly class LibraryAccess
{
    public function __construct(
        public Library $library,
        public bool $granted,
    ) {
    }
}
