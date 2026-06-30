<?php

declare(strict_types=1);

namespace Tsduck\Report;

use FFI;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Util\NativeObject;

/**
 * An asynchronous report that queues log messages for background processing.
 *
 * AsyncReport wraps a C++ AsyncReport that spawns a dedicated background
 * thread to handle log message output. This avoids blocking the calling
 * thread on I/O operations.
 *
 * This is the basic async report. For polling-based async reports (needed
 * when receiving log messages from a background thread in PHP), see
 * AbstractAsyncReport in a later unit.
 *
 * The underlying native object is owned by this PHP instance and is freed
 * when close() is called or the object is garbage collected.
 *
 * Usage:
 *   $report = new AsyncReport(AsyncReport::Debug);
 *   $report->info('This message is processed asynchronously');
 *   $report->terminate();
 *   $report->close();
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class AsyncReport extends Report
{
    /**
     * Creates a new AsyncReport with a background log processing thread.
     *
     * @param int            $maxSeverity  Initial maximum severity level
     *                                     (default: Report::Debug, all messages)
     * @param int            $logMsgCount  Maximum number of buffered log messages
     *                                     (0 = unlimited, default: 0)
     * @param bool           $synchronized If true, log messages are written
     *                                     synchronously (default: false)
     * @param Report|null    $report       Optional base report to delegate to.
     *                                     If null, the async report uses its own
     *                                     default stderr output. Note: the PHP
     *                                     binding does not currently support passing
     *                                     a custom base report to the async report
     *                                     constructor.
     */
    public function __construct(
        int $maxSeverity = Report::Debug,
        int $logMsgCount = 0,
        bool $synchronized = false,
        ?Report $report = null,
    ) {
        $ffi = LibTSDuck::getInstance();

        // tspyNewAsyncReport(int severity, int sync_log, int timed_log, size_t log_msg_count)
        // Note: timed_log is always false in the basic async report.
        $pointer = $ffi->tspyNewAsyncReport(
            $maxSeverity,
            $synchronized ? 1 : 0,
            0, // timed_log = false
            $logMsgCount,
        );

        parent::__construct($ffi, $pointer);
    }

    /**
     * Frees the underlying C++ AsyncReport object.
     *
     * This calls tspyDeleteReport which also terminates the background
     * thread. It is safe to call close() without explicitly calling
     * terminate() first -- the destructor handles cleanup.
     */
    protected function doClose(): void
    {
        $pointer = $this->getPointer();
        if ($pointer !== null) {
            $this->ffi->tspyDeleteReport($pointer);
        }
    }

    /**
     * Synchronously terminates the async log thread.
     *
     * Call this before close() to ensure all pending log messages have
     * been flushed. After calling terminate(), no further log() calls
     * should be made.
     *
     * This method is idempotent -- calling it multiple times is safe.
     */
    public function terminate(): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyTerminateAsyncReport($this->getPointer());
    }
}
