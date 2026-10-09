<?php

declare(strict_types=1);

namespace App\Library\Domain\ValueObject;

/** The outcome of releasing a library's scan claim on an operator's request. */
enum ScanClaimRelease
{
    /** The claim was ended and its scan marked failed. */
    case Released;

    /** No scan held a claim; nothing changed. */
    case NoClaim;

    /** A scan holds a claim whose lease is still running; nothing changed. */
    case Live;
}
