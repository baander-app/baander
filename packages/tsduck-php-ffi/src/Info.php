<?php

declare(strict_types=1);

namespace Tsduck;

use FFI;
use Tsduck\FFI\LibTSDuck;

/**
 * Provides TSDuck library version information.
 *
 * This is a static utility class with no instances. Use the static methods
 * to query the TSDuck library version at runtime.
 *
 * Usage:
 *   echo Info::version();    // e.g. "TSDuck - MPEG-TS Manipulation Utility - Version 3.38-3838"
 *   echo Info::intVersion(); // e.g. 32702383
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
final class Info
{
    /**
     * Info is a static utility class. No instances allowed.
     */
    private function __construct()
    {
    }

    /**
     * Returns the TSDuck library version as a human-readable string.
     *
     * The version string typically includes the library name and version number.
     * Uses the double-buffer pattern: first queries the required size with a null
     * buffer, then allocates a buffer of the correct size and retrieves the string.
     *
     * @return string The TSDuck version string, or empty string on error
     */
    public static function version(): string
    {
        $ffi = LibTSDuck::getInstance();

        // Use a fixed-size buffer. The C FromString() helper sets *size = 0
        // when buffer is null, so the two-phase pattern does not work.
        // 256 bytes = 128 UTF-16 characters, plenty for a version string.
        $bufferSize = 256;
        $buffer = $ffi->new("uint8_t[{$bufferSize}]", false, false);
        $sizePtr = $ffi->new('size_t', false, false);
        $sizePtr->cdata = $bufferSize;

        $ffi->tspyVersionString($buffer, FFI::addr($sizePtr));

        if ((int) $sizePtr->cdata === 0) {
            return '';
        }

        // The C API returns UTF-16 LE data. Convert to PHP string.
        $bytes = FFI::string($buffer, (int) $sizePtr->cdata);

        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
    }

    /**
     * Returns the TSDuck library version as an integer.
     *
     * The version integer is encoded as:
     *   major * 10000000 + minor * 100000 + patch
     *
     * For example, version 3.38-3838 is encoded as 32702383.
     *
     * @return int The TSDuck version integer
     */
    public static function intVersion(): int
    {
        $ffi = LibTSDuck::getInstance();

        return (int) $ffi->tspyVersionInteger();
    }
}
