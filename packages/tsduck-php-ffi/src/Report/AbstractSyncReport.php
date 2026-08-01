<?php

declare(strict_types=1);

namespace Tsduck\Report;

use FFI;
use Tsduck\FFI\LibTSDuck;

/**
 * An abstract synchronous report that delivers log messages to a PHP callback.
 *
 * Subclass this and override the log() method to receive log messages from
 * TSDuck. The C++ ts::py::SyncReport::writeLog method calls the PHP callback
 * synchronously on the same thread -- there is no background thread involved,
 * making FFI callbacks safe to use here.
 *
 * This is the synchronous counterpart to AbstractAsyncReport. Use this class
 * when you need to receive log messages during single-threaded operations
 * (e.g., during a DuckContext operation or synchronous TSProcessor run).
 *
 * Usage:
 *   class MyReport extends AbstractSyncReport
 *   {
 *       public function log(int $severity, string $message): void
 *       {
 *           echo "[" . $severity . "] " . $message . "\n";
 *       }
 *   }
 *
 *   $report = new MyReport(Report::Debug);
 *   $report->info('Hello, TSDuck!');
 *   $report->close();
 *
 * Thread safety: This class is NOT thread-safe. The log() callback is invoked
 * on the calling thread. Do not use this report with components that log from
 * background threads (use AbstractAsyncReport instead).
 *
 * @warning The PHP wrapper object must be kept alive for the entire lifetime
 * of any native TSDuck object (TSProcessor, DuckContext, etc.) that holds a
 * reference to this report's native pointer. If the PHP object is garbage-
 * collected while a native object still holds the pointer, the C++ side will
 * invoke the PHP callback on a freed object, causing a use-after-free crash.
 * Always store the report in a variable that outlives all native objects
 * using it. For example:
 *   $report = new MyReport();
 *   $proc = new TSProcessor($report);  // native code holds pointer to $report
 *   $proc->...();                       // safe: $report is still alive
 *   $proc->close();
 *   $report->close();                   // now safe to release
 *
 * @see AsyncReport The base class for async report functionality
 * @see Report    The base class with severity constants and log methods
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
abstract class AbstractSyncReport extends AsyncReport
{
    /**
     * Holds the FFI callback holder struct to prevent garbage collection.
     *
     * PHP FFI allows assigning PHP closures to struct fields of function
     * pointer type. The holder struct provides such a field for storing
     * the log callback. This property MUST be kept alive as long as the
     * C++ SyncReport may invoke the callback. If the holder is garbage
     * collected, PHP will free the closure trampoline and the C++ code
     * will call into freed memory, causing a use-after-free crash.
     */
    private FFI\CData $callbackHolder;

    /**
     * Creates a new AbstractSyncReport with a synchronous PHP callback.
     *
     * The constructor creates a PHP closure that bridges the C++
     * ts::py::SyncReport::writeLog calls to the PHP log() method. The
     * closure converts the UTF-16 LE message from C to a PHP UTF-8 string.
     *
     * The callback is stored in an FFI struct field (of function pointer type)
     * to prevent garbage collection. This is critical -- if the closure is
     * collected, the C++ code will call into freed memory.
     *
     * Note: This constructor calls Report::__construct() directly (skipping
     * AsyncReport::__construct()) because the native object is created by
     * tspyNewPySyncReport, not tspyNewAsyncReport. The AsyncReport base
     * class provides doClose() and terminate() which are compatible with
     * the SyncReport native object.
     *
     * @param int $maxSeverity Initial maximum severity level
     *                         (default: Report::Debug, all messages)
     */
    public function __construct(int $maxSeverity = Report::Debug)
    {
        $ffi = LibTSDuck::getInstance();

        // Allocate a holder struct for the callback function pointer.
        // PHP FFI allows assigning PHP closures to struct fields of
        // function pointer type, which creates the necessary trampoline
        // for calling back into PHP from C.
        $this->callbackHolder = $ffi->new('struct tspyCallbackHolder', false, false);

        // Assign a PHP closure to the function pointer field.
        // The closure matches the C signature:
        //   void* (*)(int severity, const UChar* message, size_t message_bytes)
        //
        // CRITICAL: The return type is void* (not void) to match
        // ts::py::SyncReport::LogCallback. The return value is unused by
        // the C++ bridge, so we return null (maps to a null pointer).
        //
        // We capture $this by reference using a local variable to avoid
        // issues with late static binding in the closure.
        $self = $this;
        $this->callbackHolder->log_callback = function (int $severity, FFI\CData $message, int $messageBytes) use ($self): ?FFI\CData {
            try {
                // Convert the UTF-16 LE message buffer to a PHP UTF-8 string.
                // $message is a pointer to raw bytes (const uint8_t* in the typedef),
                // $messageBytes is the size in bytes (not characters).
                $utf16le = FFI::string($message, $messageBytes);
                $phpString = mb_convert_encoding($utf16le, 'UTF-8', 'UTF-16LE');

                $self->log($severity, $phpString);
            } catch (\Throwable) {
                // Catch any exception from the user's log() override to
                // prevent it from propagating into the C++ call stack,
                // which would cause undefined behavior.
                // We cannot log the error here (would recurse), so we
                // silently swallow it. Users should ensure their log()
                // method does not throw.
            }

            // Return null which maps to a null void* pointer.
            // The return value is unused by the C++ bridge but must
            // match the LogCallback signature.
            return null;
        };

        // Create the native SyncReport object via tspyNewPySyncReport.
        // void* tspyNewPySyncReport(void* log, int severity)
        // Cast the function pointer value to void* for the opaque parameter.
        // $this->callbackHolder->log_callback is the function pointer value
        // (the address of the PHP trampoline), not the address of the field.
        $pointer = $ffi->tspyNewPySyncReport(
            $ffi->cast('void*', $this->callbackHolder->log_callback),
            $maxSeverity,
        );

        // Call Report::__construct() directly, skipping AsyncReport::__construct()
        // which would create an incorrect tspyNewAsyncReport native object.
        Report::__construct($ffi, $pointer);
    }

    /**
     * Logs a message at the specified severity level.
     *
     * Override this method in subclasses to receive log messages from
     * TSDuck. The default implementation is a no-op.
     *
     * Important: Do NOT call parent::log() from your override. The parent
     * Report::log() sends the message to the C++ SyncReport, which would
     * invoke this callback again, causing infinite recursion.
     *
     * @param int    $severity The severity level (one of the Report constants)
     * @param string $message  The log message in UTF-8 encoding
     */
    public function log(int $severity, string $message): void
    {
        // Default: no-op. Subclasses override to handle messages.
    }
}
