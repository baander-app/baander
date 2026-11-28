<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface VideoMatchRequestInterface
{
    /** @param list<string> $relativePaths Committed media and sidecar observations. */
    public function requestAfterImport(Uuid $libraryId, array $relativePaths): void;
}
