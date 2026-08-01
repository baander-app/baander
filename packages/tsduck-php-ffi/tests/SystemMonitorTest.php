<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use PHPUnit\Framework\TestCase;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Report\NullReport;
use Tsduck\Report\StdErrReport;
use Tsduck\SystemMonitor;

/**
 * Tests for SystemMonitor.
 *
 * Tests requiring the native libtsduck library skip gracefully when unavailable.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class SystemMonitorTest extends TestCase
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
     * Helper to skip tests when native library is unavailable.
     */
    private function requireNative(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    public function testConstructWithDefaultNullReport(): void
    {
        $this->requireNative();

        $monitor = new SystemMonitor();

        $this->assertFalse($monitor->isClosed(), 'SystemMonitor should not be closed after construction.');
        $monitor->close();
    }

    public function testConstructWithExplicitNullReport(): void
    {
        $this->requireNative();

        $report = NullReport::getInstance();
        $monitor = new SystemMonitor($report);

        $this->assertFalse($monitor->isClosed());
        $monitor->close();
    }

    public function testConstructWithStdErrReport(): void
    {
        $this->requireNative();

        $report = StdErrReport::getInstance();
        $monitor = new SystemMonitor($report);

        $this->assertFalse($monitor->isClosed());
        $monitor->close();
    }

    public function testConstructWithEmptyConfig(): void
    {
        $this->requireNative();

        $monitor = new SystemMonitor(null, '');

        $this->assertFalse($monitor->isClosed());
        $monitor->close();
    }

    public function testConstructWithConfigString(): void
    {
        $this->requireNative();

        $monitor = new SystemMonitor(null, 'some_config.xml');

        $this->assertFalse($monitor->isClosed());
        $monitor->close();
    }

    public function testConstructWithInvalidReportTypeThrows(): void
    {
        $this->requireNative();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Report must be an instance of Report or NullReport');

        new SystemMonitor(new \stdClass());
    }

    // =========================================================================
    // close() / lifecycle
    // =========================================================================

    public function testCloseIsIdempotent(): void
    {
        $this->requireNative();

        $monitor = new SystemMonitor();
        $monitor->close();
        $monitor->close(); // Should not throw.

        $this->assertTrue($monitor->isClosed());
    }

    public function testMethodAfterCloseThrowsException(): void
    {
        $this->requireNative();

        $monitor = new SystemMonitor();
        $monitor->close();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Cannot operate on a closed');

        $monitor->start();
    }

    public function testStopAfterCloseThrowsException(): void
    {
        $this->requireNative();

        $monitor = new SystemMonitor();
        $monitor->close();

        $this->expectException(TsduckException::class);

        $monitor->stop();
    }

    public function testWaitForTerminationAfterCloseThrowsException(): void
    {
        $this->requireNative();

        $monitor = new SystemMonitor();
        $monitor->close();

        $this->expectException(TsduckException::class);

        $monitor->waitForTermination();
    }

    // =========================================================================
    // start() / stop() / waitForTermination()
    // =========================================================================

    public function testStartAndStopCleanly(): void
    {
        $this->requireNative();

        $monitor = new SystemMonitor();
        $monitor->start();
        $monitor->stop();
        $monitor->waitForTermination();

        $this->assertFalse($monitor->isClosed());
        $monitor->close();
    }

    public function testWaitForTerminationWithImmediateStop(): void
    {
        $this->requireNative();

        $monitor = new SystemMonitor();
        $monitor->start();

        // Stop immediately and wait -- should return quickly.
        $monitor->stop();
        $monitor->waitForTermination();

        $this->addToAssertionCount(1);
        $monitor->close();
    }

    public function testDestructorCleansUpWithoutExplicitClose(): void
    {
        $this->requireNative();

        // Create a monitor and let it go out of scope.
        // The destructor should call close() and free the native object.
        $this->addToAssertionCount(1);
        $monitor = new SystemMonitor();
        $monitor->start();
        $monitor->stop();
        $monitor->waitForTermination();
        unset($monitor);
    }
}
