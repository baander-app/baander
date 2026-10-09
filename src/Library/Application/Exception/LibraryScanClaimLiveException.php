<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/** An operator asked to release a scan claim whose scan renewed it within its lease, so the scan may still run. */
final class LibraryScanClaimLiveException extends ConflictException
{
    public static function forLibrary(string $name): self
    {
        return new self(
            sprintf('A scan holds a live claim on the library "%s": it renewed the claim within its lease and may still be running.', $name),
            ['reason' => 'scan_claim_live'],
        );
    }
}
