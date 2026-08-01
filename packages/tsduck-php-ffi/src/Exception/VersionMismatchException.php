<?php

declare(strict_types=1);

namespace Tsduck\Exception;

/**
 * Exception thrown when the loaded libtsduck version does not meet
 * the minimum required version for this PHP binding.
 */
class VersionMismatchException extends TsduckException
{
    /**
     * @param int    $installedVersion The version integer reported by tspyVersionInteger()
     * @param int    $requiredVersion  The minimum required version integer
     * @param string $installedString  The human-readable version string (from tspyVersionString)
     */
    public function __construct(
        int $installedVersion,
        int $requiredVersion,
        string $installedString,
    ) {
        $reqMajor = intdiv($requiredVersion, 10000000);
        $reqMinor = intdiv($requiredVersion % 10000000, 100000);
        $reqPatch = $requiredVersion % 100000;

        parent::__construct(sprintf(
            'TSDuck version mismatch: requires %d.%d-%d, this library is %s (encoded: %d)',
            $reqMajor,
            $reqMinor,
            $reqPatch,
            $installedString,
            $installedVersion,
        ));
    }
}
