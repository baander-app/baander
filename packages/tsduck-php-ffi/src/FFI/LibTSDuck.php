<?php

declare(strict_types=1);

namespace Tsduck\FFI;

use FFI;
use Tsduck\Exception\TsduckException;
use Tsduck\Exception\VersionMismatchException;

/**
 * FFI singleton for loading and accessing the TSDuck native library.
 *
 * Handles platform-specific library discovery (mirroring the Python bindings),
 * FFI availability checks, and version compatibility validation.
 *
 * Library search order:
 *   1. TSDUCK environment variable (directory containing the library)
 *   2. Platform-specific paths:
 *      - Linux: LD_LIBRARY_PATH directories, /usr/local/lib on *BSD
 *      - macOS: LD_LIBRARY_PATH2, then LD_LIBRARY_PATH directories
 *      - Windows: TSDUCK env var, then Path directories
 *   3. System library search (FFI default resolution)
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
final class LibTSDuck
{
    /**
     * Minimum required TSDuck version integer.
     *
     * Encoded as: major * 10000000 + minor * 100000 + patch
     * Must be incremented when binary incompatibilities are introduced
     * between the PHP bindings and the library.
     */
    private const MIN_VERSION = 32702383;

    /**
     * The FFI instance, or null if not yet loaded.
     */
    private static ?FFI $instance = null;

    /**
     * Whether the singleton has been initialized (even if loading failed).
     */
    private static bool $initialized = false;

    /**
     * The resolved library path, or null if not applicable.
     */
    private static ?string $libraryPath = null;

    /**
     * LibTSDuck is a singleton. Use getInstance() to obtain the FFI instance.
     */
    private function __construct()
    {
    }

    /**
     * Returns the FFI instance for the TSDuck library.
     *
     * On first call, performs library discovery, FFI availability check,
     * and version validation. Subsequent calls return the cached instance.
     *
     * @return FFI The FFI instance bound to libtsduck
     *
     * @throws TsduckException If FFI is unavailable or the library cannot be found
     * @throws VersionMismatchException If the library version is too old
     */
    public static function getInstance(): FFI
    {
        if (!self::$initialized) {
            self::$instance = self::load();
            self::$initialized = true;
        }

        if (self::$instance === null) {
            throw new TsduckException(
                'TSDuck library is not available. '
                . 'A previous load attempt failed. Call reset() and retry, '
                . 'or ensure libtsduck is installed.'
            );
        }

        return self::$instance;
    }

    /**
     * Returns the resolved library path, if available.
     *
     * @return string|null The library file path, or null if resolved via system search
     */
    public static function getLibraryPath(): ?string
    {
        return self::$libraryPath;
    }

    /**
     * Checks whether the FFI instance has been successfully loaded.
     *
     * @return bool True if the library is loaded and ready to use
     */
    public static function isLoaded(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Resets the singleton. Primarily useful for testing.
     *
     * After calling this, the next call to getInstance() will
     * re-discover and re-load the library.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$initialized = false;
        self::$libraryPath = null;
    }

    /**
     * Loads the TSDuck library via FFI with platform-specific discovery.
     *
     * @return FFI The loaded FFI instance
     *
     * @throws TsduckException If FFI is unavailable or the library cannot be found
     * @throws VersionMismatchException If the library version is too old
     */
    private static function load(): FFI
    {
        self::checkFfiAvailable();

        $header = Symbols::header();
        $library = self::discoverLibrary();

        try {
            if ($library !== null) {
                self::$libraryPath = $library;
                $ffi = FFI::cdef($header, $library);
            } else {
                $ffi = FFI::cdef($header, 'tsduck');
            }
        } catch (\FFI\Exception $e) {
            throw new TsduckException(sprintf(
                'Failed to load TSDuck library: %s. '
                . 'Make sure libtsduck is installed. '
                . 'See https://tsduck.io/download for installation instructions.',
                $e->getMessage(),
            ), 0, $e);
        }

        self::checkVersion($ffi);

        return $ffi;
    }

    /**
     * Verifies that the FFI extension is available and enabled.
     *
     * @throws TsduckException If FFI is not available
     */
    private static function checkFfiAvailable(): void
    {
        if (!\extension_loaded('ffi')) {
            throw new TsduckException(
                'The FFI extension is not available. '
                . 'For CLI usage, PHP must be compiled with --enable-ffi. '
                . 'For web SAPI usage, set ffi.enable=true in php.ini or '
                . 'use PHP_FFI_ENABLE=1 environment variable.'
            );
        }
    }

    /**
     * Discovers the TSDuck shared library using platform-specific search logic.
     *
     * Mirrors the Python bindings' _searchLibTSDuck() function:
     *   - Windows: TSDUCK env var, then Path directories
     *   - macOS: LD_LIBRARY_PATH2, then LD_LIBRARY_PATH directories
     *   - Linux: LD_LIBRARY_PATH directories, /usr/local/lib on *BSD
     *
     * @return string|null The library file path, or null to fall back to system search
     */
    private static function discoverLibrary(): ?string
    {
        $system = PHP_OS_FAMILY;

        $base = match ($system) {
            'Windows' => 'tsduck.dll',
            'Darwin' => 'libtsduck.dylib',
            default => 'libtsduck.so',
        };

        $searchDirs = match ($system) {
            'Windows' => self::buildWindowsSearchDirs(),
            'Darwin' => self::buildMacSearchDirs(),
            default => self::buildLinuxSearchDirs($system),
        };

        foreach ($searchDirs as $dir) {
            $file = $dir . DIRECTORY_SEPARATOR . $base;
            if (file_exists($file) && is_readable($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Builds the library search directories for Windows.
     *
     * @return list<string> Directories to search
     */
    private static function buildWindowsSearchDirs(): array
    {
        $dirs = [];

        $tsduck = getenv('TSDUCK');
        if ($tsduck !== false && $tsduck !== '') {
            $dirs[] = $tsduck;
        }

        $path = getenv('Path');
        if ($path !== false && $path !== '') {
            foreach (explode(PATH_SEPARATOR, $path) as $dir) {
                if ($dir !== '') {
                    $dirs[] = $dir;
                }
            }
        }

        return $dirs;
    }

    /**
     * Builds the library search directories for macOS.
     *
     * On macOS, LD_LIBRARY_PATH is not passed to shell-scripts for security reasons.
     * A backup version LD_LIBRARY_PATH2 is defined to test development versions.
     *
     * @return list<string> Directories to search
     */
    private static function buildMacSearchDirs(): array
    {
        $dirs = [];

        $ld2 = getenv('LD_LIBRARY_PATH2');
        if ($ld2 !== false && $ld2 !== '') {
            foreach (explode(PATH_SEPARATOR, $ld2) as $dir) {
                if ($dir !== '') {
                    $dirs[] = $dir;
                }
            }
        }

        $ld = getenv('LD_LIBRARY_PATH');
        if ($ld !== false && $ld !== '') {
            foreach (explode(PATH_SEPARATOR, $ld) as $dir) {
                if ($dir !== '') {
                    $dirs[] = $dir;
                }
            }
        }

        return $dirs;
    }

    /**
     * Builds the library search directories for Linux and other Unix-like systems.
     *
     * On FreeBSD, OpenBSD, and DragonFlyBSD, the system's find_library()
     * may not find .so files without a major version in the default location.
     * We add /usr/local/lib as a fallback.
     *
     * @param string $system The PHP_OS_FAMILY value
     *
     * @return list<string> Directories to search
     */
    private static function buildLinuxSearchDirs(string $system): array
    {
        $dirs = [];

        $tsduck = getenv('TSDUCK');
        if ($tsduck !== false && $tsduck !== '') {
            $dirs[] = $tsduck;
        }

        $ld = getenv('LD_LIBRARY_PATH');
        if ($ld !== false && $ld !== '') {
            foreach (explode(PATH_SEPARATOR, $ld) as $dir) {
                if ($dir !== '') {
                    $dirs[] = $dir;
                }
            }
        }

        // On *BSD systems, add /usr/local/lib as fallback since
        // the system library resolver may not find .so without major version.
        if (str_starts_with(strtolower(PHP_OS), 'freebsd')
            || str_starts_with(strtolower(PHP_OS), 'openbsd')
            || str_starts_with(strtolower(PHP_OS), 'dragonfly')
        ) {
            $dirs[] = '/usr/local/lib';
        }

        return $dirs;
    }

    /**
     * Validates that the loaded library meets the minimum version requirement.
     *
     * @param FFI $ffi The loaded FFI instance
     *
     * @throws VersionMismatchException If the version is too old
     */
    private static function checkVersion(FFI $ffi): void
    {
        $installedVersion = $ffi->tspyVersionInteger();

        if ($installedVersion < self::MIN_VERSION) {
            // Retrieve the human-readable version string for the error message.
            // Use a fixed-size buffer — the C FromString() helper sets *size = 0
            // when buffer is null, so the two-phase pattern does not work.
            $bufferSize = 256;
            $buffer = FFI::new("uint8_t[{$bufferSize}]");
            $versionSize = FFI::new('size_t');
            $versionSize->cdata = $bufferSize;
            $ffi->tspyVersionString($buffer, FFI::addr($versionSize));

            $versionString = FFI::string($buffer, (int) $versionSize->cdata);

            throw new VersionMismatchException(
                $installedVersion,
                self::MIN_VERSION,
                $versionString,
            );
        }
    }
}
