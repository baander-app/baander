<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Library\Domain\ValueObject\LibraryClaimKind;
use App\Shared\Application\Exception\ConflictException;

/**
 * An operator asked to release a library's claim whose holder, a scan or a delete with files,
 * renewed it within its lease, so it may still run.
 */
final class LibraryScanClaimLiveException extends ConflictException
{
    public static function heldBy(string $name, LibraryClaimKind $holder): self
    {
        return new self(
            sprintf(
                '%s holds a live claim on the library "%s": it renewed the claim within its lease and may still be running.',
                match ($holder) {
                    LibraryClaimKind::Scan => 'A scan',
                    LibraryClaimKind::Delete => 'A delete with files',
                },
                $name,
            ),
            ['reason' => 'scan_claim_live', 'holder' => $holder->value],
        );
    }
}
