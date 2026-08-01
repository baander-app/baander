<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use PHPUnit\Framework\TestCase;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Report\AsyncReport;
use Tsduck\Report\NullReport;
use Tsduck\Report\Report;
use Tsduck\Report\StdErrReport;

/**
 * Tests for the Report hierarchy: Report, NullReport, StdErrReport, AsyncReport.
 *
 * Tests requiring the native libtsduck library skip gracefully when unavailable.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class ReportTest extends TestCase
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
        // Reset singletons before each test for isolation.
        if (self::$nativeAvailable) {
            NullReport::reset();
            StdErrReport::reset();
        }
    }

    // =========================================================================
    // Report severity constants
    // =========================================================================

    public function testSeverityConstantsMatchPythonBinding(): void
    {
        $this->assertSame(-5, Report::Fatal, 'Fatal should be -5.');
        $this->assertSame(-4, Report::Severe, 'Severe should be -4.');
        $this->assertSame(-3, Report::Error, 'Error should be -3.');
        $this->assertSame(-2, Report::Warning, 'Warning should be -2.');
        $this->assertSame(-1, Report::Info, 'Info should be -1.');
        $this->assertSame(0, Report::Verbose, 'Verbose should be 0.');
        $this->assertSame(1, Report::Debug, 'Debug should be 1.');
    }

    public function testSeverityConstantsAreOrdered(): void
    {
        $this->assertLessThan(Report::Severe, Report::Fatal);
        $this->assertLessThan(Report::Error, Report::Severe);
        $this->assertLessThan(Report::Warning, Report::Error);
        $this->assertLessThan(Report::Info, Report::Warning);
        $this->assertLessThan(Report::Verbose, Report::Info);
        $this->assertLessThan(Report::Debug, Report::Verbose);
    }

    // =========================================================================
    // NullReport singleton
    // =========================================================================

    public function testNullReportGetInstanceReturnsSameInstance(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $a = NullReport::getInstance();
        $b = NullReport::getInstance();

        $this->assertSame($a, $b, 'NullReport::getInstance() should return the same instance.');
    }

    public function testNullReportCloseIsNoOp(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = NullReport::getInstance();
        $report->close();

        // Should not throw -- close is a no-op for singletons.
        $this->addToAssertionCount(1);
    }

    public function testNullReportLogDoesNotThrow(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = NullReport::getInstance();

        // These should silently discard messages without throwing.
        $report->error('test error');
        $report->warning('test warning');
        $report->info('test info');
        $report->verbose('test verbose');
        $report->debug('test debug');
        $report->log(Report::Error, 'test log');

        $this->addToAssertionCount(1);
    }

    public function testNullReportGetPointerReturnsValidPointer(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = NullReport::getInstance();
        $pointer = $report->getPointer();

        $this->assertNotNull($pointer, 'NullReport::getPointer() should return a non-null pointer.');
    }

    // =========================================================================
    // StdErrReport singleton
    // =========================================================================

    public function testStdErrReportGetInstanceReturnsSameInstance(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $a = StdErrReport::getInstance();
        $b = StdErrReport::getInstance();

        $this->assertSame($a, $b, 'StdErrReport::getInstance() should return the same instance.');
    }

    public function testStdErrReportCloseIsNoOp(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = StdErrReport::getInstance();
        $report->close();

        // Should not throw.
        $this->addToAssertionCount(1);
    }

    public function testStdErrReportLogDoesNotThrow(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = StdErrReport::getInstance();

        // These should write to stderr without throwing.
        $report->error('test error');
        $report->warning('test warning');
        $report->info('test info');

        $this->addToAssertionCount(1);
    }

    public function testStdErrReportGetPointerReturnsValidPointer(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = StdErrReport::getInstance();
        $pointer = $report->getPointer();

        $this->assertNotNull($pointer, 'StdErrReport::getPointer() should return a non-null pointer.');
    }

    public function testStdErrReportDifferentFromNullReport(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $null = NullReport::getInstance();
        $stderr = StdErrReport::getInstance();

        $this->assertNotSame($null, $stderr, 'NullReport and StdErrReport should be different instances.');
    }

    // =========================================================================
    // AsyncReport
    // =========================================================================

    public function testAsyncReportConstructsWithDefaults(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new AsyncReport();

        $this->assertFalse($report->isClosed(), 'New AsyncReport should not be closed.');
        $report->close();
    }

    public function testAsyncReportConstructsWithCustomSeverity(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new AsyncReport(Report::Warning);

        $this->assertFalse($report->isClosed());
        $report->close();
    }

    public function testAsyncReportLogDoesNotThrow(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new AsyncReport(Report::Debug);

        $report->error('test error');
        $report->warning('test warning');
        $report->info('test info');
        $report->debug('test debug');

        $report->close();

        $this->addToAssertionCount(1);
    }

    public function testAsyncReportCloseIsIdempotent(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new AsyncReport();
        $report->close();
        $report->close(); // Should not throw.

        $this->assertTrue($report->isClosed());
    }

    public function testAsyncReportMethodAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new AsyncReport();
        $report->close();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Cannot operate on a closed');

        $report->info('should throw');
    }

    public function testAsyncReportTerminateDoesNotThrow(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new AsyncReport();
        $report->terminate();
        $report->close();

        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Report::header() static method
    // =========================================================================

    public function testReportHeaderReturnsString(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $header = Report::header(Report::Error);

        $this->assertIsString($header);
    }

    public function testReportHeaderForErrorIsNotEmpty(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $header = Report::header(Report::Error);

        $this->assertNotEmpty($header, 'Error header should not be empty.');
    }

    public function testReportHeaderForFatalIsNotEmpty(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $header = Report::header(Report::Fatal);

        $this->assertNotEmpty($header, 'Fatal header should not be empty.');
    }

    public function testReportHeaderForDebugIsNotEmpty(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $header = Report::header(Report::Debug);

        $this->assertNotEmpty($header, 'Debug header should not be empty.');
    }

    // =========================================================================
    // Report::setMaxSeverity()
    // =========================================================================

    public function testAsyncReportSetMaxSeverity(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new AsyncReport();
        $report->setMaxSeverity(Report::Warning);

        $this->addToAssertionCount(1);
        $report->close();
    }

    public function testNullReportSetMaxSeverity(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = NullReport::getInstance();
        $report->setMaxSeverity(Report::Error);

        $this->addToAssertionCount(1);
    }
}
