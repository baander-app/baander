<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use PHPUnit\Framework\TestCase;
use Tsduck\DuckContext;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Info;
use Tsduck\Report\NullReport;
use Tsduck\SectionFile;
use Tsduck\Report\StdErrReport;

/**
 * Tests for SectionFile and Info.
 *
 * Tests requiring the native libtsduck library skip gracefully when unavailable.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class SectionFileTest extends TestCase
{
    /**
     * Whether the TSDuck native library is available.
     */
    private static bool $nativeAvailable;

    /**
     * Path to a temporary binary section file used by tests.
     */
    private string $tempFile;

    public static function setUpBeforeClass(): void
    {
        self::$nativeAvailable = \extension_loaded('ffi');
        if (self::$nativeAvailable) {
            try {
                LibTSDuck::getInstance();
            } catch (TsduckException $e) {
                self::$nativeAvailable = false;
            }
        }
    }

    protected function setUp(): void
    {
        if (self::$nativeAvailable) {
            NullReport::reset();
            StdErrReport::reset();
        }

        $this->tempFile = tempnam(sys_get_temp_dir(), 'tsduck_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            @unlink($this->tempFile);
        }
    }

    /**
     * Creates a DuckContext with NullReport for tests.
     */
    private function createContext(): DuckContext
    {
        return new DuckContext(NullReport::getInstance());
    }

    /**
     * Creates a SectionFile for tests.
     */
    private function createSectionFile(DuckContext $duck = null): SectionFile
    {
        return new SectionFile($duck ?? $this->createContext());
    }

    // =========================================================================
    // SectionFile: CRC32 constants
    // =========================================================================

    public function testCRC32ConstantsMatchPythonBinding(): void
    {
        $this->assertSame(0, SectionFile::CRC32_IGNORE, 'CRC32_IGNORE should be 0.');
        $this->assertSame(1, SectionFile::CRC32_CHECK, 'CRC32_CHECK should be 1.');
        $this->assertSame(2, SectionFile::CRC32_COMPUTE, 'CRC32_COMPUTE should be 2.');
    }

    // =========================================================================
    // SectionFile: Constructor and lifecycle
    // =========================================================================

    public function testConstructWithDuckContext(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $sf = $this->createSectionFile($duck);

        $this->assertFalse($sf->isClosed(), 'SectionFile should not be closed after construction.');
        $sf->close();
        $duck->close();
    }

    public function testCloseIsIdempotent(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();
        $sf->close();
        $sf->close(); // Should not throw.

        $this->assertTrue($sf->isClosed());
    }

    public function testMethodAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();
        $sf->close();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Cannot operate on a closed');

        $sf->sectionsCount();
    }

    // =========================================================================
    // SectionFile: clear() and initial state
    // =========================================================================

    public function testClearOnEmptyFile(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();
        $sf->clear();

        $this->assertSame(0, $sf->sectionsCount(), 'Empty SectionFile should have 0 sections.');
        $this->assertSame(0, $sf->tablesCount(), 'Empty SectionFile should have 0 tables.');
        $this->assertSame(0, $sf->binarySize(), 'Empty SectionFile should have binary size 0.');
        $sf->close();
    }

    // =========================================================================
    // SectionFile: loadBinary() error handling
    // =========================================================================

    public function testLoadBinaryNonexistentFileThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Failed to load binary section file');

        $sf->loadBinary('/nonexistent/path/sections.bin');
    }

    // =========================================================================
    // SectionFile: saveBinary() / loadBinary() round-trip
    // =========================================================================

    public function testSaveBinaryEmptyFileSucceeds(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();

        // Saving an empty section file should succeed.
        $sf->saveBinary($this->tempFile);

        $this->assertFileExists($this->tempFile);
        $this->assertSame('', file_get_contents($this->tempFile), 'Empty section file should save as empty.');
        $sf->close();
    }

    public function testLoadBinaryEmptyFileSucceeds(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        // Create an empty file.
        file_put_contents($this->tempFile, '');

        $sf = $this->createSectionFile();
        $sf->loadBinary($this->tempFile);

        $this->assertSame(0, $sf->sectionsCount());
        $sf->close();
    }

    // =========================================================================
    // SectionFile: fromBinary() / toBinary()
    // =========================================================================

    public function testToBinaryOnEmptyFileReturnsEmptyString(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();

        $this->assertSame('', $sf->toBinary(), 'toBinary() on empty file should return empty string.');
        $sf->close();
    }

    // =========================================================================
    // SectionFile: toXML() / toJSON() on empty file
    // =========================================================================

    public function testToXMLReturnsNonEmptyString(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();
        $xml = $sf->toXML();

        $this->assertIsString($xml, 'toXML() should return a string.');
        // Even an empty SectionFile produces an XML header/structure.
        $this->assertNotEmpty($xml, 'toXML() should return non-empty string.');
        $sf->close();
    }

    public function testToJSONReturnsNonEmptyString(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();
        $json = $sf->toJSON();

        $this->assertIsString($json, 'toJSON() should return a string.');
        $this->assertNotEmpty($json, 'toJSON() should return non-empty string.');
        $sf->close();
    }

    // =========================================================================
    // SectionFile: saveXML() / saveJSON()
    // =========================================================================

    public function testSaveXMLSucceeds(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();
        $sf->saveXML($this->tempFile);

        $this->assertFileExists($this->tempFile);

        $content = file_get_contents($this->tempFile);
        $this->assertNotEmpty($content, 'Saved XML should not be empty.');
        $sf->close();
    }

    public function testSaveJSONSucceeds(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();
        $sf->saveJSON($this->tempFile);

        $this->assertFileExists($this->tempFile);

        $content = file_get_contents($this->tempFile);
        $this->assertNotEmpty($content, 'Saved JSON should not be empty.');
        $sf->close();
    }

    public function testSaveXMLToUnwritablePathThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Permission-based tests are not reliable on Windows.');
        }

        $sf = $this->createSectionFile();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Failed to save XML section file');

        $sf->saveXML('/nonexistent/directory/output.xml');
    }

    // =========================================================================
    // SectionFile: setCRCValidation()
    // =========================================================================

    public function testSetCRCValidationIgnore(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();
        $sf->setCRCValidation(SectionFile::CRC32_IGNORE);

        $this->addToAssertionCount(1);
        $sf->close();
    }

    public function testSetCRCValidationCheck(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();
        $sf->setCRCValidation(SectionFile::CRC32_CHECK);

        $this->addToAssertionCount(1);
        $sf->close();
    }

    public function testSetCRCValidationCompute(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();
        $sf->setCRCValidation(SectionFile::CRC32_COMPUTE);

        $this->addToAssertionCount(1);
        $sf->close();
    }

    // =========================================================================
    // SectionFile: reorganizeEITs()
    // =========================================================================

    public function testReorganizeEITsOnEmptyFileDoesNotThrow(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();

        // Should not throw on an empty section file.
        $sf->reorganizeEITs();
        $sf->reorganizeEITs(2026, 4, 20);

        $this->addToAssertionCount(1);
        $sf->close();
    }

    // =========================================================================
    // SectionFile: toBinary() / saveBinary() / loadBinary() round-trip
    // =========================================================================

    public function testToBinarySaveBinaryRoundTrip(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sf = $this->createSectionFile();

        // Empty file round-trip.
        $binary = $sf->toBinary();
        $sf->saveBinary($this->tempFile);

        $fileContent = file_get_contents($this->tempFile);
        $this->assertSame($binary, $fileContent, 'toBinary() should match saved binary file.');

        $sf->close();
    }

    // =========================================================================
    // Info: version()
    // =========================================================================

    public function testVersionReturnsNonEmptyString(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $version = Info::version();

        $this->assertIsString($version, 'Info::version() should return a string.');
        $this->assertNotEmpty($version, 'Info::version() should not be empty.');
        $this->assertStringContainsString('TSDuck', $version, 'Version string should contain "TSDuck".');
    }

    // =========================================================================
    // Info: intVersion()
    // =========================================================================

    public function testIntVersionReturnsPositiveInt(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $version = Info::intVersion();

        $this->assertIsInt($version, 'Info::intVersion() should return an integer.');
        $this->assertGreaterThan(0, $version, 'Version integer should be positive.');
    }

    public function testIntVersionMatchesEncoding(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $version = Info::intVersion();
        $major = intdiv($version, 10000000);
        $remainder = $version % 10000000;
        $minor = intdiv($remainder, 100000);
        $patch = $remainder % 100000;

        $this->assertGreaterThanOrEqual(0, $major, 'Major version should be non-negative.');
        $this->assertGreaterThanOrEqual(0, $minor, 'Minor version should be non-negative.');
        $this->assertGreaterThanOrEqual(0, $patch, 'Patch version should be non-negative.');
    }

    // =========================================================================
    // Info: version() and intVersion() consistency
    // =========================================================================

    public function testVersionAndIntVersionAreConsistent(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $versionStr = Info::version();
        $versionInt = Info::intVersion();

        // The version string should contain the major.minor portion.
        $major = intdiv($versionInt, 10000000);
        $minor = intdiv($versionInt % 10000000, 100000);

        $this->assertStringContainsString(
            (string) $major,
            $versionStr,
            'Version string should contain the major version number.',
        );
        $this->assertStringContainsString(
            (string) $minor,
            $versionStr,
            'Version string should contain the minor version number.',
        );
    }
}
