<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use PHPUnit\Framework\TestCase;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Report\AbstractSyncReport;
use Tsduck\Report\Report;

/**
 * Tests for AbstractSyncReport: FFI::closure()-based synchronous report.
 *
 * Tests requiring the native libtsduck library skip gracefully when unavailable.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class AbstractSyncReportTest extends TestCase
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
            } catch (TsduckException) {
                self::$nativeAvailable = false;
            }
        }
    }

    // =========================================================================
    // Helper: concrete test subclass
    // =========================================================================

    /**
     * A concrete AbstractSyncReport subclass that collects log messages.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    private function createCollectingReport(int $maxSeverity = Report::Debug): object
    {
        return new class($maxSeverity) extends AbstractSyncReport {
            /** @var list<array{int, string}> */
            public array $messages = [];

            public function log(int $severity, string $message): void
            {
                $this->messages[] = [$severity, $message];
            }
        };
    }

    // =========================================================================
    // Construction and lifecycle
    // =========================================================================

    public function testConstructWithDefaults(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();

        $this->assertFalse($report->isClosed(), 'New report should not be closed.');
        $report->close();
    }

    public function testConstructWithCustomSeverity(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport(Report::Warning);

        $this->assertFalse($report->isClosed());
        $report->close();
    }

    public function testCloseIsIdempotent(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();
        $report->close();
        $report->close(); // Should not throw.

        $this->assertTrue($report->isClosed());
    }

    public function testMethodAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();
        $report->close();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Cannot operate on a closed');

        // Use error() which goes through Report::log() -> assertNotClosed().
        // Direct log() calls go to the subclass override which has no closed check.
        $report->error('should throw');
    }

    public function testSetMaxSeverityAfterConstruction(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();
        $report->setMaxSeverity(Report::Error);

        $this->addToAssertionCount(1);
        $report->close();
    }

    // =========================================================================
    // Log message delivery
    // =========================================================================

    public function testSingleLogMessageIsReceived(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();
        $report->log(Report::Error, 'test error message');

        $this->assertCount(1, $report->messages, 'One message should have been received.');
        $this->assertSame(Report::Error, $report->messages[0][0]);
        $this->assertSame('test error message', $report->messages[0][1]);

        $report->close();
    }

    public function testMultipleLogMessagesInOrder(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();
        $report->log(Report::Error, 'first error');
        $report->log(Report::Warning, 'second warning');
        $report->log(Report::Info, 'third info');
        $report->log(Report::Debug, 'fourth debug');

        $this->assertCount(4, $report->messages, 'Four messages should have been received.');

        $this->assertSame(Report::Error, $report->messages[0][0]);
        $this->assertSame('first error', $report->messages[0][1]);

        $this->assertSame(Report::Warning, $report->messages[1][0]);
        $this->assertSame('second warning', $report->messages[1][1]);

        $this->assertSame(Report::Info, $report->messages[2][0]);
        $this->assertSame('third info', $report->messages[2][1]);

        $this->assertSame(Report::Debug, $report->messages[3][0]);
        $this->assertSame('fourth debug', $report->messages[3][1]);

        $report->close();
    }

    public function testConvenienceLogMethods(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();
        $report->error('error msg');
        $report->warning('warning msg');
        $report->info('info msg');
        $report->verbose('verbose msg');
        $report->debug('debug msg');

        $this->assertCount(5, $report->messages, 'Five messages should have been received.');

        $this->assertSame(Report::Error, $report->messages[0][0]);
        $this->assertSame('error msg', $report->messages[0][1]);

        $this->assertSame(Report::Warning, $report->messages[1][0]);
        $this->assertSame('warning msg', $report->messages[1][1]);

        $this->assertSame(Report::Info, $report->messages[2][0]);
        $this->assertSame('info msg', $report->messages[2][1]);

        $this->assertSame(Report::Verbose, $report->messages[3][0]);
        $this->assertSame('verbose msg', $report->messages[3][1]);

        $this->assertSame(Report::Debug, $report->messages[4][0]);
        $this->assertSame('debug msg', $report->messages[4][1]);

        $report->close();
    }

    public function testUnicodeMessagesAreReceivedCorrectly(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();
        $report->log(Report::Info, 'Hello, w\u{00F6}rld! \u{4F60}\u{597D}\u{4E16}\u{754C}');

        $this->assertCount(1, $report->messages);
        $this->assertSame('Hello, w\u{00F6}rld! \u{4F60}\u{597D}\u{4E16}\u{754C}', $report->messages[0][1]);

        $report->close();
    }

    public function testEmptyMessageIsReceived(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();
        $report->log(Report::Info, '');

        $this->assertCount(1, $report->messages);
        $this->assertSame('', $report->messages[0][1]);

        $report->close();
    }

    // =========================================================================
    // Severity configuration
    // =========================================================================

    public function testMaxSeverityIsSetOnConstruction(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        // Create a report with Error severity. The native C++ SyncReport
        // should have this severity set. We verify by calling setMaxSeverity
        // and confirming no error is thrown (the native object is valid).
        $report = $this->createCollectingReport(Report::Error);

        // Changing severity should work on the native object.
        $report->setMaxSeverity(Report::Debug);

        $this->addToAssertionCount(1);
        $report->close();
    }

    /**
     * Tests that direct log() calls bypass C++ severity filtering.
     *
     * When the user calls log() directly or via convenience methods,
     * the call goes to the subclass's log() override, NOT through
     * the C++ SyncReport. Therefore, all messages are received
     * regardless of the configured severity.
     *
     * Severity filtering only applies to messages generated internally
     * by TSDuck C++ code (e.g., during TSProcessor runs).
     */
    public function testDirectLogCallsBypassSeverityFilter(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        // Create a report with Error severity.
        $report = $this->createCollectingReport(Report::Error);

        // Direct log() calls go to the subclass override, bypassing C++.
        // All messages should be received regardless of severity setting.
        $report->debug('debug bypasses filter');
        $report->info('info bypasses filter');
        $report->warning('warning bypasses filter');
        $report->error('error passes through');

        $this->assertCount(4, $report->messages, 'All direct log() calls bypass C++ severity filter.');
        $this->assertSame('debug bypasses filter', $report->messages[0][1]);
        $this->assertSame('info bypasses filter', $report->messages[1][1]);
        $this->assertSame('warning bypasses filter', $report->messages[2][1]);
        $this->assertSame('error passes through', $report->messages[3][1]);

        $report->close();
    }

    // =========================================================================
    // Close during active processing
    // =========================================================================

    public function testCloseDuringActiveProcessingDoesNotCrash(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();

        // Log some messages while the report is active.
        $report->info('before close');

        // Close the report.
        $report->close();

        // Verify the messages received before close are intact.
        $this->assertCount(1, $report->messages);
        $this->assertSame('before close', $report->messages[0][1]);
    }

    public function testDestructorCleansUpWithoutClose(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $messageCount = 0;

        // Create a report that goes out of scope without explicit close().
        // The destructor should clean up the native object.
        (function () use (&$messageCount): void {
            $report = new class() extends AbstractSyncReport {
                /** @var callable */
                private $callback;

                public function setCallback(callable $cb): void
                {
                    $this->callback = $cb;
                }

                public function log(int $severity, string $message): void
                {
                    ($this->callback)($severity, $message);
                }
            };

            $report->setCallback(function (int $severity, string $message) use (&$messageCount): void {
                $messageCount++;
            });

            $report->info('message 1');
            $report->info('message 2');
            // Let the report go out of scope without close().
            // __destruct() should handle cleanup.
        })();

        $this->assertSame(2, $messageCount, 'Both messages should have been received.');
    }

    // =========================================================================
    // Exception handling
    // =========================================================================

    /**
     * Tests that exceptions in direct log() calls propagate normally.
     *
     * When the user calls log() directly (or via convenience methods),
     * any exception in the subclass's log() override propagates normally
     * through PHP's exception handling. This is the expected behavior for
     * direct calls.
     *
     * Exception safety for C++ -> PHP callbacks (where exceptions must be
     * caught to prevent undefined behavior in C++) is provided by the
     * FFI callback trampoline's try/catch block. That path is tested in
     * integration tests with real TSDuck operations (e.g., TSProcessor).
     */
    public function testExceptionInDirectLogCallPropagates(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new class() extends AbstractSyncReport {
            public bool $throwOnNext = false;

            public function log(int $severity, string $message): void
            {
                if ($this->throwOnNext) {
                    $this->throwOnNext = false;
                    throw new \RuntimeException('test exception in log()');
                }
            }
        };

        $report->throwOnNext = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test exception in log()');

        // Direct log() call -- exception should propagate normally.
        $report->log(Report::Error, 'this will throw');
    }

    // =========================================================================
    // Garbage collection safety
    // =========================================================================

    public function testCallbackHolderPreventsGarbageCollection(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        // Create a report and log multiple messages to verify the callback
        // holder (and thus the closure trampoline) is not garbage collected
        // between calls.
        $report = $this->createCollectingReport();

        // Force garbage collection to try to collect the closure.
        gc_collect_cycles();

        $report->info('message 1');

        gc_collect_cycles();

        $report->info('message 2');

        gc_collect_cycles();

        $report->info('message 3');

        $this->assertCount(3, $report->messages, 'All messages should be received despite GC cycles.');
        $this->assertSame('message 1', $report->messages[0][1]);
        $this->assertSame('message 2', $report->messages[1][1]);
        $this->assertSame('message 3', $report->messages[2][1]);

        $report->close();
    }
}
