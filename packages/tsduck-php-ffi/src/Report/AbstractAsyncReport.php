<?php

declare(strict_types=1);

namespace Tsduck\Report;

use FFI;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;

/**
 * A polling-based asynchronous report for receiving log messages from C++ background threads.
 *
 * This class extends AsyncReport and uses a polling pattern instead of direct
 * FFI callbacks to safely receive log messages from the C++ AsyncReport's
 * background thread. PHP's FFI closures are NOT safe to invoke from non-PHP
 * threads (no GIL equivalent), so this class polls a thread-safe message queue
 * that the C++ PollingAsyncReport bridge populates.
 *
 * Usage:
 *   class MyReport extends AbstractAsyncReport
 *   {
 *       public function processMessages(array $messages): void
 *       {
 *           foreach ($messages as [$severity, $message]) {
 *               echo "[$severity] $message\n";
 *           }
 *       }
 *   }
 *
 *   $report = new MyReport(Report::Debug);
 *   $report->run(); // Calls processMessages() in a loop
 *   $report->close();
 *
 * Or use a manual poll loop:
 *   $report = new MyReport(Report::Debug);
 *   while (!$done) {
 *       $messages = $report->waitForMessages(1000);
 *       $report->processMessages($messages);
 *   }
 *   $report->close();
 *
 * Thread safety: This class IS safe for use with C++ background threads.
 * The polling pattern ensures all log processing happens on the PHP thread.
 *
 * @see AsyncReport          The base class for async report functionality
 * @see Report               The base class with severity constants and log methods
 * @see AbstractSyncReport   The synchronous counterpart using FFI::closure()
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
abstract class AbstractAsyncReport extends AsyncReport
{
    /**
     * Creates a new polling-based asynchronous report.
     *
     * The constructor calls tspyphpNewPollingAsyncReport instead of
     * tspyNewAsyncReport. This creates a C++ PollingAsyncReport that
     * queues log messages into a thread-safe queue instead of invoking
     * a callback directly.
     *
     * Note: This constructor calls Report::__construct() directly (skipping
     * AsyncReport::__construct()) because the native object is created by
     * tspyphpNewPollingAsyncReport, not tspyNewAsyncReport.
     *
     * @param int  $maxSeverity  Initial maximum severity level
     *                           (default: Report::Debug, all messages)
     * @param int  $logMsgCount  Maximum number of buffered log messages in the
     *                           internal AsyncReport queue (0 = unlimited, default: 0)
     * @param bool $synchronized If true, messages are delivered synchronously
     *                           (no background thread, default: false)
     * @param int  $maxQueueSize Maximum number of messages in the polling queue
     *                           (default: 1024)
     */
    public function __construct(
        int $maxSeverity = Report::Debug,
        int $logMsgCount = 0,
        bool $synchronized = false,
        int $maxQueueSize = 1024,
    ) {
        $ffi = LibTSDuck::getInstance();

        // tspyphpNewPollingAsyncReport(int severity, int sync_log, size_t log_msg_count, size_t max_queue_size)
        $pointer = $ffi->tspyphpNewPollingAsyncReport(
            $maxSeverity,
            $synchronized ? 1 : 0,
            $logMsgCount,
            $maxQueueSize,
        );

        // Call Report::__construct() directly, skipping AsyncReport::__construct()
        // which would create an incorrect tspyNewAsyncReport native object.
        Report::__construct($ffi, $pointer);
    }

    /**
     * Frees the underlying C++ PollingAsyncReport object.
     *
     * Calls tspyphpDeletePollingAsyncReport which also terminates the
     * background thread (if running).
     */
    protected function doClose(): void
    {
        $pointer = $this->getPointer();
        if ($pointer !== null) {
            $this->ffi->tspyphpDeletePollingAsyncReport($pointer);
        }
    }

    /**
     * Polls for queued log messages, blocking up to the specified timeout.
     *
     * Returns an array of [int $severity, string $message] pairs. If no
     * messages are available within the timeout, returns an empty array.
     *
     * The C++ bridge batches all available messages into a single buffer
     * with UTF-16 encoding. This method drains all available messages from
     * that buffer and returns them as an array.
     *
     * Timeout semantics:
     *   - 0: Non-blocking poll (returns immediately)
     *   - -1: Block forever until at least one message is available
     *   - N > 0: Block up to N milliseconds
     *
     * @param int $timeoutMs Maximum time to wait in milliseconds
     *                       (default: 1000)
     *
     * @return list<array{int, string}> Array of [severity, message] pairs
     *
     * @throws TsduckException If the object has been closed
     */
    public function waitForMessages(int $timeoutMs = 1000): array
    {
        $this->assertNotClosed();

        // Allocate a large buffer for UTF-16 message data.
        // Each message is: [1 UChar severity] [N UChars message text]
        // Messages separated by: [0xFFFF UChar]
        // A typical log message is ~200 chars = 400 bytes UTF-16.
        // With severity + separator overhead, budget ~500 bytes per message.
        // 64KB buffer handles ~128 typical messages per poll.
        $bufferSize = 65536;
        $buffer = $this->ffi->new("uint8_t[{$bufferSize}]", false, false);
        $sizePtr = $this->ffi->new('size_t', false, false);
        $sizePtr->cdata = $bufferSize;

        // tspyphpPollReportMessages(void* report, uint8_t* buffer, size_t* buffer_size, int timeout_ms) -> int
        $result = $this->ffi->tspyphpPollReportMessages(
            $this->getPointer(),
            $buffer,
            FFI::addr($sizePtr),
            $timeoutMs,
        );

        if (!$result) {
            // No messages available (timeout or non-blocking poll).
            return [];
        }

        // Parse the UTF-16 buffer into [severity, message] pairs.
        return $this->parseMessageBuffer($buffer, (int) $sizePtr->cdata);
    }

    /**
     * Processes a batch of log messages received from the polling queue.
     *
     * Override this method in subclasses to handle log messages. The default
     * implementation is a no-op.
     *
     * This method is called by run() for each batch of messages. When using
     * a manual poll loop, call this method yourself with the result of
     * waitForMessages().
     *
     * @param list<array{int, string}> $messages Array of [severity, message] pairs
     */
    public function processMessages(array $messages): void
    {
        // Default: no-op. Subclasses override to handle messages.
    }

    /**
     * Runs a poll loop that continuously receives and processes messages.
     *
     * This method blocks, calling processMessages() for each batch of
     * messages received. It runs until the report is closed (via close()
     * or the object going out of scope).
     *
     * This is a convenience method for simple use cases. For more control
     * over the poll loop (e.g., to integrate with an event loop or add
     * shutdown conditions), use waitForMessages() directly.
     *
     * @param int $timeoutMs Maximum time to wait per poll iteration
     *                       (default: 1000)
     */
    public function run(int $timeoutMs = 1000): void
    {
        while (!$this->isClosed()) {
            $messages = $this->waitForMessages($timeoutMs);
            if (!empty($messages)) {
                $this->processMessages($messages);
            }
        }
    }

    /**
     * Parses the UTF-16 message buffer into [severity, message] pairs.
     *
     * The C++ bridge serializes messages as:
     *   [severity: 1 UChar (2 bytes)] [message: N UChars] [0xFFFF separator]
     *
     * The last message does NOT have a trailing separator.
     *
     * @param FFI\CData $buffer The FFI buffer containing UTF-16 data
     * @param int       $size   The number of valid bytes in the buffer
     *
     * @return list<array{int, string}> Array of [severity, message] pairs
     */
    private function parseMessageBuffer(FFI\CData $buffer, int $size): array
    {
        if ($size < 2) {
            return [];
        }

        // Read the raw bytes from the FFI buffer.
        $bytes = FFI::string($buffer, $size);

        // Convert the entire buffer from UTF-16LE to a PHP string of code units.
        // We need to work with the raw UTF-16 code units to find separators.
        $codeUnits = [];
        $numUnits = intdiv($size, 2);
        for ($i = 0; $i < $numUnits; $i++) {
            $codeUnits[] = ord($bytes[$i * 2]) | (ord($bytes[$i * 2 + 1]) << 8);
        }

        $messages = [];
        $pos = 0;

        while ($pos < $numUnits) {
            // Read severity (1 code unit).
            $severity = $codeUnits[$pos];
            $pos++;

            // Find the end of this message (next 0xFFFF separator or end of buffer).
            $start = $pos;
            while ($pos < $numUnits && $codeUnits[$pos] !== 0xFFFF) {
                $pos++;
            }

            // Extract the message text as raw bytes and convert from UTF-16LE to UTF-8.
            $msgBytes = '';
            for ($i = $start; $i < $pos; $i++) {
                $msgBytes .= chr($codeUnits[$i] & 0xFF)
                    . chr(($codeUnits[$i] >> 8) & 0xFF);
            }

            $message = ($msgBytes !== '')
                ? mb_convert_encoding($msgBytes, 'UTF-8', 'UTF-16LE')
                : '';

            $messages[] = [$severity, $message];

            // Skip the 0xFFFF separator if present.
            if ($pos < $numUnits && $codeUnits[$pos] === 0xFFFF) {
                $pos++;
            }
        }

        return $messages;
    }
}
