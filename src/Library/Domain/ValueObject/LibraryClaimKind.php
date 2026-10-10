<?php

declare(strict_types=1);

namespace App\Library\Domain\ValueObject;

/**
 * What holds a library's claim. One claim holds a library at a time, so a scan and a delete with
 * files never run on one library together.
 */
enum LibraryClaimKind: string
{
    /** A scan, which sets the discovery status to `scanning` while it holds the claim. */
    case Scan = 'scan';

    /** A delete with files, which leaves the discovery status and the last scan time alone. */
    case Delete = 'delete';
}
