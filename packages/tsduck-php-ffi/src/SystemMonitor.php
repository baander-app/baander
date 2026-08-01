<?php

declare(strict_types=1);

namespace Tsduck;

use FFI;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Report\NullReport;
use Tsduck\Report\Report;
use Tsduck\Util\InBuffer;
use Tsduck\Util\NativeObject;

/**
 * Monitors system resources (CPU, memory, etc.) for TSDuck processing.
 *
 * SystemMonitor runs a background monitoring thread that periodically
 * samples system resource usage. It is typically used alongside a
 * TSProcessor or InputSwitcher to track resource consumption during
 * MPEG-TS processing.
 *
 * The monitoring thread is started explicitly via start() and stopped
 * via stop() followed by waitForTermination(). The underlying native
 * object is freed by close() or the destructor.
 *
 * Usage:
 *   $monitor = new SystemMonitor();
 *   $monitor->start();
 *   // ... run TSProcessor ...
 *   $monitor->stop();
 *   $monitor->waitForTermination();
 *   $monitor->close();
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class SystemMonitor extends NativeObject
{
    /**
     * The report used by this monitor for logging.
     */
    private readonly object $report;

    /**
     * Creates a new SystemMonitor.
     *
     * Creates the monitoring object but does not start the monitoring
     * thread yet. Call start() to begin monitoring.
     *
     * @param Report|null $report The report object to use for logging.
     *                                      Defaults to NullReport::getInstance()
     *                                      which silently discards all messages.
     * @param string                 $config The monitoring configuration file name,
     *                                      or empty string to use the default configuration.
     *
     * @throws TsduckException If the report is not a valid Report instance
     */
    public function __construct(object $report = null, string $config = '')
    {
        $ffi = LibTSDuck::getInstance();

        if ($report === null) {
            $report = NullReport::getInstance();
        }

        $this->report = $report;

        // Get the opaque pointer from the report.
        $reportPointer = match (true) {
            $report instanceof Report => $report->nativePointer(),
            default => throw new TsduckException(sprintf(
                'Report must be an instance of Report, %s given.',
                get_debug_type($report),
            )),
        };

        // Convert config string to UTF-16 LE for the C API.
        $configBuf = new InBuffer($ffi);
        $configBuf->append($config);

        // void* tspyNewSystemMonitor(void* report, const uint8_t* config, size_t config_size)
        $pointer = $ffi->tspyNewSystemMonitor(
            $reportPointer,
            $configBuf->getBuffer(),
            $configBuf->getSize(),
        );

        parent::__construct($ffi, $pointer);
    }

    /**
     * Frees the underlying C++ SystemMonitor object.
     */
    protected function doClose(): void
    {
        $pointer = $this->getPointer();
        if ($pointer !== null) {
            $this->ffi->tspyDeleteSystemMonitor($pointer);
        }
    }

    /**
     * Starts the monitoring thread.
     *
     * Begins periodic sampling of system resources in a background thread.
     * The thread runs until stop() is called.
     *
     * @throws TsduckException If the monitor has been closed
     */
    public function start(): void
    {
        $this->assertNotClosed();

        // void tspyStartSystemMonitor(void* pymon)
        $this->ffi->tspyStartSystemMonitor($this->getPointer());
    }

    /**
     * Requests the monitoring thread to stop.
     *
     * This method returns immediately. Use waitForTermination() to
     * synchronously wait for the monitoring thread to actually stop.
     *
     * @throws TsduckException If the monitor has been closed
     */
    public function stop(): void
    {
        $this->assertNotClosed();

        // void tspyStopSystemMonitor(void* pymon)
        $this->ffi->tspyStopSystemMonitor($this->getPointer());
    }

    /**
     * Synchronously waits for the monitoring thread to terminate.
     *
     * Blocks the calling thread until the monitoring thread has fully
     * stopped. Typically called after stop() to ensure clean shutdown.
     *
     * @throws TsduckException If the monitor has been closed
     */
    public function waitForTermination(): void
    {
        $this->assertNotClosed();

        // void tspyWaitSystemMonitor(void* pymon)
        $this->ffi->tspyWaitSystemMonitor($this->getPointer());
    }
}
