<?php

declare(strict_types=1);

namespace Tsduck\Report;

use FFI;
use Tsduck\FFI\LibTSDuck;

/**
 * A report that sends all messages to standard error (stderr).
 *
 * StdErrReport is a singleton wrapping a process-global C++ CerrReport instance.
 * It writes all logged messages to stderr, making it useful for CLI applications
 * and debugging.
 *
 * Since the underlying C++ object is process-global, doClose() is a no-op and
 * the native object is never freed. The user-visible close() method is
 * inherited from NativeObject and is safe to call but does not free anything.
 *
 * StdErrReport extends Report, so it can be used anywhere a Report is expected
 * without requiring union types (Report|StdErrReport).
 *
 * Usage:
 *   $report = StdErrReport::getInstance();
 *   $report->error('This message goes to stderr');
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
final class StdErrReport extends Report
{
    /**
     * The singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Private constructor. Use getInstance() to obtain the singleton.
     *
     * Calls tspyStdErrReport() to obtain the process-global singleton pointer.
     * The pointer is passed to Report::__construct() which delegates to
     * NativeObject::__construct(). doClose() is overridden as a no-op to
     * prevent freeing the process-global native object.
     */
    private function __construct()
    {
        $ffi = LibTSDuck::getInstance();
        $pointer = $ffi->tspyStdErrReport();
        parent::__construct($ffi, $pointer);
    }

    /**
     * Returns the singleton StdErrReport instance.
     *
     * On first call, creates the instance by calling tspyStdErrReport().
     * Subsequent calls return the cached instance.
     *
     * @return self The singleton StdErrReport
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Resets the singleton. Primarily useful for testing.
     *
     * After calling this, the next call to getInstance() will create a new instance.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * No-op: does not free the process-global native object.
     *
     * The underlying C++ StdErrReport is a process-global singleton and must
     * not be freed. This override prevents NativeObject::close() from
     * attempting to free it via the default doClose() behavior.
     */
    protected function doClose(): void
    {
        // Process-global singleton -- never freed.
    }
}
