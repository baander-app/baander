<?php

declare(strict_types=1);

namespace App\Library\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/**
 * A running scan found that its claim is gone: it went longer than its lease without renewing
 * it and another scan took the library over, or an operator released the claim. The scan stops
 * so that two scans never write the same library's file index.
 */
final class LibraryScanClaimLostException extends ConflictException
{
    public static function forLibrary(string $name): self
    {
        return new self(
            sprintf('The scan of the library "%s" stopped: it no longer holds the scan claim.', $name),
            ['reason' => 'scan_claim_lost'],
        );
    }
}
