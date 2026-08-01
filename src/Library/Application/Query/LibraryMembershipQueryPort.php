<?php

declare(strict_types=1);

namespace App\Library\Application\Query;

use App\Shared\Domain\Model\Uuid;

interface LibraryMembershipQueryPort
{
    /**
     * @return list<string> List of user UUID strings who have access to the given library
     */
    public function findUserIdsForLibrary(Uuid $libraryId): array;
}
