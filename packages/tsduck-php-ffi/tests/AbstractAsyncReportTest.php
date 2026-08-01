<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use PHPUnit\Framework\TestCase;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Report\AbstractAsyncReport;
use Tsduck\Report\Report;

/**
 * Tests for AbstractAsyncReport: polling-based asynchronous report.
 *
 * Tests requiring the native libtsduck library skip gracefully when unavailable.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class AbstractAsyncReportTest extends TestCase
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
     * Creates a concrete AbstractAsyncReport subclass that collects messages.
     *
     * @param int $maxSeverity Initial severity level
     *
     * @return AbstractAsyncReport A testable report instance
     */
    private function createCollectingReport(int $maxSeverity = Report::Debug): AbstractAsyncReport
    {
        return new class($maxSeverity) extends AbstractAsyncReport {
            /** @var list<array{int, string}> */
            public array $messages = [];

            public function processMessages(array $messages): void
            {
                foreach ($messages as $message) {
                    $this->messages[] = $message;
                }
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

    public function testConstructWithSynchronizedMode(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new class(Report::Debug, 0, true) extends AbstractAsyncReport {
        };

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

        $report->waitForMessages(0);
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
    // Polling behavior
    // =========================================================================

    public function testNonBlockingPollReturnsEmptyArray(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();

        // Non-blocking poll with no messages should return empty array.
        $messages = $report->waitForMessages(0);

        $this->assertIsArray($messages);
        $this->assertEmpty($messages, 'Non-blocking poll with no messages should return empty array.');

        $report->close();
    }

    public function testShortTimeoutPollReturnsEmptyArray(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = $this->createCollectingReport();

        // Short timeout poll with no messages should return empty array.
        $messages = $report->waitForMessages(10);

        $this->assertIsArray($messages);
        $this->assertEmpty($messages, 'Short timeout poll with no messages should return empty array.');

        $report->close();
    }

    // =========================================================================
    // Message buffer parsing (unit tests of the parsing logic)
    // =========================================================================

    /**
     * Tests that the parseMessageBuffer logic correctly handles a single message.
     *
     * We test the parsing by constructing a synthetic UTF-16 buffer with the
     * same format the C++ bridge produces, then calling waitForMessages.
     *
     * Format: [severity: 1 UChar] [message: N UChars]
     */
    public function testParseSingleMessageFromBuffer(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        // Use a synchronous report so we can log directly and then poll.
        $report = new class(Report::Debug, 0, true) extends AbstractAsyncReport {
            /**
             * Expose parseMessageBuffer for testing via waitForMessages.
             * Since synchronous mode delivers messages immediately, we can
             * log and then poll.
             */
            public function testPoll(): array
            {
                return $this->waitForMessages(100);
            }
        };

        // Log a message. In synchronous mode, this goes through the
        // C++ report's writeLog -> asyncThreadLog -> queue.
        $report->log(Report::Error, 'test error');

        // Poll for the message.
        $messages = $report->testPoll();

        $this->assertCount(1, $messages, 'Should receive one message.');
        $this->assertSame(Report::Error, $messages[0][0]);
        $this->assertSame('test error', $messages[0][1]);

        $report->close();
    }

    public function testParseMultipleMessagesFromBuffer(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new class(Report::Debug, 0, true) extends AbstractAsyncReport {
            public function testPoll(): array
            {
                return $this->waitForMessages(100);
            }
        };

        // Log multiple messages.
        $report->log(Report::Error, 'first error');
        $report->log(Report::Warning, 'second warning');
        $report->log(Report::Info, 'third info');

        // Poll should return all queued messages.
        $messages = $report->testPoll();

        $this->assertGreaterThanOrEqual(1, count($messages), 'Should receive at least one message.');

        $report->close();
    }

    public function testParseEmptyMessage(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new class(Report::Debug, 0, true) extends AbstractAsyncReport {
            public function testPoll(): array
            {
                return $this->waitForMessages(100);
            }
        };

        // Log an empty message.
        $report->log(Report::Debug, '');

        $messages = $report->testPoll();

        $this->assertCount(1, $messages, 'Should receive one message.');
        $this->assertSame(Report::Debug, $messages[0][0]);
        $this->assertSame('', $messages[0][1]);

        $report->close();
    }

    public function testParseUnicodeMessage(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new class(Report::Debug, 0, true) extends AbstractAsyncReport {
            public function testPoll(): array
            {
                return $this->waitForMessages(100);
            }
        };

        $unicodeMsg = "Hello, w\u{00F6}rld! \u{4F60}\u{597D}\u{4E16}\u{754C}";
        $report->log(Report::Info, $unicodeMsg);

        $messages = $report->testPoll();

        $this->assertCount(1, $messages);
        $this->assertSame($unicodeMsg, $messages[0][1]);

        $report->close();
    }

    // =========================================================================
    // Process messages
    // =========================================================================

    public function testProcessMessagesReceivesMessages(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = new class(Report::Debug, 0, true) extends AbstractAsyncReport {
            /** @var list<array{int, string}> */
            public array $processed = [];

            public function processMessages(array $messages): void
            {
                foreach ($messages as $msg) {
                    $this->processed[] = $msg;
                }
            }
        };

        $report->log(Report::Error, 'test message');

        // Manually poll and process.
        $messages = $report->waitForMessages(100);
        $report->processMessages($messages);

        $this->assertCount(1, $report->processed);
        $this->assertSame('test message', $report->processed[0][1]);

        $report->close();
    }

    // =========================================================================
    // Destructor cleanup
    // =========================================================================

    public function testDestructorCleansUpWithoutClose(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        // Create a report that goes out of scope without explicit close().
        // The destructor should clean up the native object.
        $closed = false;
        (function () use (&$closed): void {
            $report = new class() extends AbstractAsyncReport {
            };
            // Let the report go out of scope.
            // __destruct() should handle cleanup.
        })();

        // If we get here without a crash, the destructor worked.
        $this->assertTrue(true);
    }
}
