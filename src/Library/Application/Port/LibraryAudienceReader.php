<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface LibraryAudienceReader
{
    /** @return list<string> */
    public function readableLibraryIds(Uuid $principal, bool $administrator): array;
}
