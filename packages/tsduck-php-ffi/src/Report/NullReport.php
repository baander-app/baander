<?php

declare(strict_types=1);

namespace Tsduck\Report;

use FFI;
use Tsduck\FFI\LibTSDuck;

/**
 * A report that silently drops all messages.
 *
 * NullReport is a singleton wrapping a process-global C++ NullReport instance.
 * It discards all logged messages, making it useful as a default report when
 * logging output is not desired.
 *
 * Since the underlying C++ object is process-global, doClose() is a no-op and
 * the native object is never freed. The user-visible close() method is
 * inherited from NativeObject and is safe to call but does not free anything.
 *
 * NullReport extends Report, so it can be used anywhere a Report is expected
 * without requiring union types (Report|NullReport).
 *
 * Usage:
 *   $report = NullReport::getInstance();
 *   $report->error('This message is silently discarded');
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
final class NullReport extends Report
{
    /**
     * The singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Private constructor. Use getInstance() to obtain the singleton.
     *
     * Calls tspyNullReport() to obtain the process-global singleton pointer.
     * The pointer is passed to Report::__construct() which delegates to
     * NativeObject::__construct(). doClose() is overridden as a no-op to
     * prevent freeing the process-global native object.
     */
    private function __construct()
    {
        $ffi = LibTSDuck::getInstance();
        $pointer = $ffi->tspyNullReport();
        parent::__construct($ffi, $pointer);
    }

    /**
     * Returns the singleton NullReport instance.
     *
     * On first call, creates the instance by calling tspyNullReport().
     * Subsequent calls return the cached instance.
     *
     * @return self The singleton NullReport
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
     * The underlying C++ NullReport is a process-global singleton and must
     * not be freed. This override prevents NativeObject::close() from
     * attempting to free it via the default doClose() behavior.
     */
    protected function doClose(): void
    {
        // Process-global singleton -- never freed.
    }
}
