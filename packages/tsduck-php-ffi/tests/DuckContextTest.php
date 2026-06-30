<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use PHPUnit\Framework\TestCase;
use Tsduck\DuckContext;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Report\NullReport;
use Tsduck\Report\StdErrReport;

/**
 * Tests for DuckContext.
 *
 * Tests requiring the native libtsduck library skip gracefully when unavailable.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class DuckContextTest extends TestCase
{
    /**
     * Whether the TSDuck native library is available.
     */
    private static bool $nativeAvailable;

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
    }

    /**
     * Creates a DuckContext with NullReport for tests that need a fresh context.
     */
    private function createContext(): DuckContext
    {
        return new DuckContext(NullReport::getInstance());
    }

    // =========================================================================
    // Standard constants
    // =========================================================================

    public function testStandardConstantsMatchPythonBinding(): void
    {
        $this->assertSame(0x00, DuckContext::NONE, 'NONE should be 0x00.');
        $this->assertSame(0x01, DuckContext::MPEG, 'MPEG should be 0x01.');
        $this->assertSame(0x02, DuckContext::DVB, 'DVB should be 0x02.');
        $this->assertSame(0x04, DuckContext::SCTE, 'SCTE should be 0x04.');
        $this->assertSame(0x08, DuckContext::ATSC, 'ATSC should be 0x08.');
        $this->assertSame(0x10, DuckContext::ISDB, 'ISDB should be 0x10.');
        $this->assertSame(0x20, DuckContext::JAPAN, 'JAPAN should be 0x20.');
        $this->assertSame(0x40, DuckContext::ABNT, 'ABNT should be 0x40.');
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    public function testConstructWithDefaultNullReport(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = new DuckContext();

        $this->assertFalse($duck->isClosed(), 'DuckContext should not be closed after construction.');
        $duck->close();
    }

    public function testConstructWithNullReportExplicitly(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = NullReport::getInstance();
        $duck = new DuckContext($report);

        $this->assertFalse($duck->isClosed());
        $duck->close();
    }

    public function testConstructWithStdErrReport(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = StdErrReport::getInstance();
        $duck = new DuckContext($report);

        $this->assertFalse($duck->isClosed());
        $duck->close();
    }

    // =========================================================================
    // close() / lifecycle
    // =========================================================================

    public function testCloseIsIdempotent(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->close();
        $duck->close(); // Should not throw.

        $this->assertTrue($duck->isClosed());
    }

    public function testMethodAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->close();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Cannot operate on a closed');

        $duck->standards();
    }

    // =========================================================================
    // setDefaultCharset()
    // =========================================================================

    public function testSetDefaultCharsetWithEmptyString(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setDefaultCharset('');

        $this->addToAssertionCount(1);
        $duck->close();
    }

    public function testSetDefaultCharsetWithValidName(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setDefaultCharset('ISO-8859-15');

        $this->addToAssertionCount(1);
        $duck->close();
    }

    public function testSetDefaultCharsetWithInvalidNameThrows(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Failed to set default charset');

        $duck->setDefaultCharset('NOT_A_REAL_CHARSET');
    }

    // =========================================================================
    // setDefaultCASId()
    // =========================================================================

    public function testSetDefaultCASId(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setDefaultCASId(0x0100);

        $this->addToAssertionCount(1);
        $duck->close();
    }

    public function testSetDefaultCASIdZero(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setDefaultCASId(0);

        $this->addToAssertionCount(1);
        $duck->close();
    }

    // =========================================================================
    // setDefaultPDS()
    // =========================================================================

    public function testSetDefaultPDS(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setDefaultPDS(0x00000010);

        $this->addToAssertionCount(1);
        $duck->close();
    }

    public function testSetDefaultPDSZero(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setDefaultPDS(0);

        $this->addToAssertionCount(1);
        $duck->close();
    }

    // =========================================================================
    // addStandards() / resetStandards() / standards()
    // =========================================================================

    public function testStandardsInitiallyNone(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $standards = $duck->standards();

        $this->assertSame(DuckContext::NONE, $standards, 'Initial standards should be NONE.');
        $duck->close();
    }

    public function testAddStandardsDVB(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->addStandards(DuckContext::DVB);

        $standards = $duck->standards();
        $this->assertSame(DuckContext::DVB, $standards, 'Standards should be DVB.');
        $duck->close();
    }

    public function testAddStandardsMultiple(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->addStandards(DuckContext::DVB, DuckContext::MPEG);

        $standards = $duck->standards();
        $this->assertSame(
            DuckContext::DVB | DuckContext::MPEG,
            $standards,
            'Standards should include both DVB and MPEG.',
        );
        $duck->close();
    }

    public function testAddStandardsVariadic(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->addStandards(DuckContext::ISDB, DuckContext::JAPAN, DuckContext::ABNT);

        $standards = $duck->standards();
        $this->assertSame(
            DuckContext::ISDB | DuckContext::JAPAN | DuckContext::ABNT,
            $standards,
            'Standards should include ISDB, JAPAN, and ABNT.',
        );
        $duck->close();
    }

    public function testResetStandardsClearsAll(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->addStandards(DuckContext::DVB);
        $this->assertNotSame(DuckContext::NONE, $duck->standards());

        $duck->resetStandards();

        $this->assertSame(DuckContext::NONE, $duck->standards(), 'Standards should be NONE after reset.');
        $duck->close();
    }

    public function testResetStandardsWithMask(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->addStandards(DuckContext::DVB, DuckContext::ATSC);
        $duck->resetStandards(DuckContext::DVB);

        $standards = $duck->standards();
        $this->assertSame(DuckContext::DVB, $standards, 'Standards should be DVB after reset with mask.');
        $duck->close();
    }

    // =========================================================================
    // setTimeReferenceOffset()
    // =========================================================================

    public function testSetTimeReferenceOffsetZero(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setTimeReferenceOffset(0);

        $this->addToAssertionCount(1);
        $duck->close();
    }

    public function testSetTimeReferenceOffsetPositive(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setTimeReferenceOffset(32400000); // JST = +9 hours in ms

        $this->addToAssertionCount(1);
        $duck->close();
    }

    public function testSetTimeReferenceOffsetNegative(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setTimeReferenceOffset(-18000000); // -5 hours in ms

        $this->addToAssertionCount(1);
        $duck->close();
    }

    // =========================================================================
    // setTimeReference()
    // =========================================================================

    public function testSetTimeReferenceUTC(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setTimeReference('UTC');

        $this->addToAssertionCount(1);
        $duck->close();
    }

    public function testSetTimeReferenceJST(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setTimeReference('JST');

        $this->addToAssertionCount(1);
        $duck->close();
    }

    public function testSetTimeReferenceWithOffset(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setTimeReference('UTC+09:00');

        $this->addToAssertionCount(1);
        $duck->close();
    }

    public function testSetTimeReferenceWithNegativeOffset(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();
        $duck->setTimeReference('UTC-05:00');

        $this->addToAssertionCount(1);
        $duck->close();
    }

    public function testSetTimeReferenceInvalidNameThrows(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $duck = $this->createContext();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Failed to set time reference');

        $duck->setTimeReference('INVALID_TIMEZONE');
    }
}
