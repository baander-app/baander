<?php

declare(strict_types=1);

namespace Tsduck;

use FFI;
use Tsduck\Exception\TSPStartException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Report\NullReport;
use Tsduck\Report\Report;
use Tsduck\Util\InBuffer;
use Tsduck\Util\StructMapper;

/**
 * A wrapper class for the C++ TSProcessor ("tsp" command).
 *
 * TSProcessor processes an MPEG-TS stream through a chain of plugins.
 * It reads TS packets from an input plugin, processes them through
 * zero or more packet processor plugins, and writes them to an output
 * plugin.
 *
 * Usage:
 *   $tsp = new TSProcessor();
 *   $tsp->setPlugins('-I', 'file', 'input.ts', '-O', 'file', 'output.ts');
 *   $tsp->start();
 *   $tsp->waitForTermination();
 *   $tsp->close();
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class TSProcessor extends PluginEventHandlerRegistry
{
    /**
     * Ignore "joint termination" options in plugins.
     */
    public bool $ignoreJointTermination = false;

    /**
     * Size in bytes of the global TS packet buffer.
     */
    public int $bufferSize = 16 * 1024 * 1024;

    /**
     * Maximum processed packets before flush (0 = default).
     */
    public int $maxFlushedPackets = 0;

    /**
     * Maximum packets per input operation (0 = default).
     */
    public int $maxInputPackets = 0;

    /**
     * Maximum packets per output operation (0 = unlimited).
     */
    public int $maxOutputPackets = 0;

    /**
     * Initial number of input packets to read before starting processing (0 = default).
     */
    public int $initialInputPackets = 0;

    /**
     * Add null packets every N input packets (two values: [instuff_nullpkt, instuff_inpkt]).
     *
     * @var list<int>
     */
    public array $addInputStuffing = [0, 0];

    /**
     * Add null packets before actual input.
     */
    public int $addStartStuffing = 0;

    /**
     * Add null packets after end of actual input.
     */
    public int $addStopStuffing = 0;

    /**
     * Fixed input bitrate in bits/second (0 = auto-detect).
     */
    public int $bitrate = 0;

    /**
     * Bitrate adjust interval in milliseconds.
     */
    public int $bitrateAdjustInterval = 5000;

    /**
     * Timeout on input operations in milliseconds (0 = blocking).
     */
    public int $receiveTimeout = 0;

    /**
     * Log plugin index with plugin name.
     */
    public bool $logPluginIndex = false;

    /**
     * The UTF-16 LE plugin specification buffer.
     *
     * Built by setPlugins() and passed to the native start function.
     */
    private ?InBuffer $pluginsBuffer = null;

    /**
     * Creates a new TSProcessor.
     *
     * @param Report|null $report The report object to use for logging.
     *                                       Defaults to NullReport::getInstance().
     */
    public function __construct(object $report = null)
    {
        $ffi = LibTSDuck::getInstance();

        if ($report === null) {
            $report = NullReport::getInstance();
        }

        $reportPointer = self::resolveReportPointer($report);

        // void* tspyNewTSProcessor(void* report)
        $pointer = $ffi->tspyNewTSProcessor($reportPointer);

        parent::__construct($ffi, $pointer);
    }

    /**
     * Sets the plugin specification for the TS processing chain.
     *
     * Each argument is a plugin specification element. Use '-I' to start
     * an input plugin, '-P' for a processor plugin, and '-O' for an output
     * plugin. The remaining arguments for each plugin follow until the next
     * -I/-P/-O marker.
     *
     * This method returns $this for fluent chaining.
     *
     * @param string ...$plugins Plugin specification elements
     *
     * @return $this This instance for fluent chaining
     */
    public function setPlugins(string ...$plugins): self
    {
        $this->pluginsBuffer = new InBuffer($this->ffi);
        foreach ($plugins as $plugin) {
            $this->pluginsBuffer->append($plugin);
        }

        return $this;
    }

    /**
     * Starts the TS processor.
     *
     * Builds the native tspyTSProcessorArgs struct from the current property
     * values and the plugin buffer, then calls tspyStartTSProcessor.
     *
     * @throws TSPStartException If the processor fails to start
     */
    public function start(): void
    {
        $this->assertNotClosed();

        $args = $this->ffi->new('struct tspyTSProcessorArgs', false, false);

        StructMapper::set($args, 'ignore_joint_termination', $this->ignoreJointTermination);
        StructMapper::set($args, 'buffer_size', $this->bufferSize);
        StructMapper::set($args, 'max_flushed_packets', $this->maxFlushedPackets);
        StructMapper::set($args, 'max_input_packets', $this->maxInputPackets);
        StructMapper::set($args, 'max_output_packets', $this->maxOutputPackets);
        StructMapper::set($args, 'initial_input_packets', $this->initialInputPackets);
        StructMapper::set($args, 'add_input_stuffing_0', $this->addInputStuffing[0]);
        StructMapper::set($args, 'add_input_stuffing_1', $this->addInputStuffing[1]);
        StructMapper::set($args, 'add_start_stuffing', $this->addStartStuffing);
        StructMapper::set($args, 'add_stop_stuffing', $this->addStopStuffing);
        StructMapper::set($args, 'bitrate', $this->bitrate);
        StructMapper::set($args, 'bitrate_adjust_interval', $this->bitrateAdjustInterval);
        StructMapper::set($args, 'receive_timeout', $this->receiveTimeout);
        StructMapper::set($args, 'log_plugin_index', $this->logPluginIndex);

        $buf = $this->pluginsBuffer ?? new InBuffer($this->ffi);
        StructMapper::setString($args, 'plugins', 'plugins_size', $buf);

        // int tspyStartTSProcessor(void* tsp, const struct tspyTSProcessorArgs* pyargs)
        $result = (int) $this->ffi->tspyStartTSProcessor(
            $this->getPointer(),
            FFI::addr($args),
        );

        if ($result === 0) {
            throw new TSPStartException('Error starting TS processor');
        }
    }

    /**
     * Aborts the TS processor.
     *
     * Requests the processor to stop. This method returns immediately;
     * use waitForTermination() to wait until the processor has fully stopped.
     */
    public function abort(): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyAbortTSProcessor($this->getPointer());
    }

    /**
     * Suspends the calling thread until TS processing is completed.
     *
     * Blocks until the processor has finished processing all packets and
     * all plugins have terminated.
     */
    public function waitForTermination(): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyWaitTSProcessor($this->getPointer());
    }

    /**
     * Frees the underlying C++ TSProcessor object.
     */
    protected function doClose(): void
    {
        $pointer = $this->getPointer();
        if ($pointer !== null) {
            $this->ffi->tspyDeleteTSProcessor($pointer);
        }
    }

    /**
     * Resolves the native pointer from a Report object.
     *
     * @param object $report The report instance
     *
     * @return FFI\CData The native opaque pointer
     *
     * @throws \InvalidArgumentException If the report type is not supported
     */
    private static function resolveReportPointer(object $report): FFI\CData
    {
        return match (true) {
            $report instanceof Report => $report->nativePointer(),
            default => throw new \InvalidArgumentException(sprintf(
                'Report must be an instance of Report, %s given.',
                get_debug_type($report),
            )),
        };
    }
}
