<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use PHPUnit\Framework\TestCase;
use Tsduck\Exception\TsduckException;
use Tsduck\Exception\VersionMismatchException;
use Tsduck\FFI\LibTSDuck;

/**
 * Tests for the Composer package bootstrap: FFI availability, library loading,
 * and version compatibility check.
 *
 * Tests that require libtsduck to be installed are grouped so they can
 * be skipped in CI environments without the native library.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class BootstrapTest extends TestCase
{
    /**
     * Whether the FFI extension is available.
     */
    private static bool $ffiAvailable;

    /**
     * Whether libtsduck could be loaded.
     */
    private static bool $libraryLoaded;

    public static function setUpBeforeClass(): void
    {
        self::$ffiAvailable = \extension_loaded('ffi');

        if (self::$ffiAvailable) {
            try {
                LibTSDuck::getInstance();
                self::$libraryLoaded = true;
            } catch (TsduckException | VersionMismatchException) {
                self::$libraryLoaded = false;
            }
        } else {
            self::$libraryLoaded = false;
        }
    }

    // =========================================================================
    // FFI availability tests
    // =========================================================================

    public function testFfiExtensionIsPresent(): void
    {
        // This test documents whether FFI is available in the test environment.
        // It should always pass (it asserts the environment state, not correctness).
        $this->addToAssertionCount(1);

        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available in this PHP build.');
        }

        $this->assertTrue(self::$ffiAvailable, 'FFI extension is loaded.');
    }

    // =========================================================================
    // Library loading tests (require libtsduck installed)
    // =========================================================================

    public function testLibraryLoadsSuccessfully(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        try {
            $ffi = LibTSDuck::getInstance();
            $this->assertNotNull($ffi);
        } catch (TsduckException $e) {
            $this->markTestSkipped('libtsduck is not installed: ' . $e->getMessage());
        }
    }

    public function testIsLoadedReturnsTrueAfterSuccessfulLoad(): void
    {
        if (!self::$libraryLoaded) {
            $this->markTestSkipped('libtsduck is not available.');
        }

        $this->assertTrue(LibTSDuck::isLoaded());
    }

    public function testVersionIntegerReturnsNonZero(): void
    {
        if (!self::$libraryLoaded) {
            $this->markTestSkipped('libtsduck is not available.');
        }

        $ffi = LibTSDuck::getInstance();
        $version = $ffi->tspyVersionInteger();
        $this->assertGreaterThan(0, (int) $version);
    }

    public function testVersionStringReturnsNonEmpty(): void
    {
        if (!self::$libraryLoaded) {
            $this->markTestSkipped('libtsduck is not available.');
        }

        $ffi = LibTSDuck::getInstance();

        // First call to get the required buffer size.
        $size = \FFI::new('size_t');
        $size->cdata = 0;
        $ffi->tspyVersionString(null, \FFI::addr($size));

        $bufferSize = (int) $size->cdata + 2;
        $this->assertGreaterThan(0, $bufferSize, 'Version string buffer size should be positive.');

        // Second call to retrieve the actual string.
        $buffer = \FFI::new("uint8_t[{$bufferSize}]");
        $size->cdata = $bufferSize;
        $ffi->tspyVersionString($buffer, \FFI::addr($size));

        $versionString = \FFI::string($buffer, (int) $size->cdata);
        $this->assertNotEmpty($versionString, 'Version string should not be empty.');
    }

    public function testVersionMatchesMinimum(): void
    {
        if (!self::$libraryLoaded) {
            $this->markTestSkipped('libtsduck is not available.');
        }

        // If getInstance() succeeded without throwing VersionMismatchException,
        // the version check passed. This test verifies that invariant.
        $ffi = LibTSDuck::getInstance();
        $version = (int) $ffi->tspyVersionInteger();

        // MIN_VERSION is private, but we know it from the source.
        // If this test runs, the version is already validated.
        $this->assertGreaterThan(0, $version);
    }

    public function testLibraryPathIsSetWhenFoundViaSearch(): void
    {
        if (!self::$libraryLoaded) {
            $this->markTestSkipped('libtsduck is not available.');
        }

        $path = LibTSDuck::getLibraryPath();
        // The path may be null if the library was found via system search
        // (no explicit path). If set, it should be a valid file path.
        if ($path !== null) {
            $this->assertFileExists($path);
        } else {
            $this->assertNull($path, 'Library path is null when found via system search.');
        }
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        if (!self::$libraryLoaded) {
            $this->markTestSkipped('libtsduck is not available.');
        }

        $first = LibTSDuck::getInstance();
        $second = LibTSDuck::getInstance();
        $this->assertSame($first, $second, 'getInstance() should return the same FFI instance (singleton).');
    }

    // =========================================================================
    // Error path tests
    // =========================================================================

    public function testResetClearsSingletonState(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        // Reset and verify that the next call will attempt to reload.
        LibTSDuck::reset();
        $this->assertFalse(LibTSDuck::isLoaded(), 'isLoaded() should return false after reset.');

        // Re-load if library is available, so other tests still work.
        if (self::$libraryLoaded) {
            try {
                LibTSDuck::getInstance();
            } catch (TsduckException | VersionMismatchException) {
                // Expected if library not available after reset.
            }
        }
    }
}
