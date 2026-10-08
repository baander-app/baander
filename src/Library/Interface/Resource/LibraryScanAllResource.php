<?php

declare(strict_types=1);

namespace App\Library\Interface\Resource;

use App\Library\Application\DTO\LibraryScanClaimResult;
use App\Shared\Interface\Resource\AbstractResource;

/** The `data` of POST /api/libraries/scan-all. */
final class LibraryScanAllResource extends AbstractResource
{
    /** @return array{dispatched: int, skipped: int} */
    public static function from(mixed $source): array
    {
        assert($source instanceof LibraryScanClaimResult);

        return [
            'dispatched' => count($source->claimed),
            'skipped' => count($source->skipped),
        ];
    }
}
