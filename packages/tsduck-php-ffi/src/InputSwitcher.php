<?php

declare(strict_types=1);

namespace Tsduck;

use FFI;
use Tsduck\Exception\SwitchStartException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Report\NullReport;
use Tsduck\Report\Report;
use Tsduck\Util\InBuffer;
use Tsduck\Util\StructMapper;

/**
 * A wrapper class for the C++ InputSwitcher ("tsswitch" command).
 *
 * InputSwitcher manages one or more input plugins and one output plugin,
 * allowing dynamic switching between inputs during MPEG-TS processing.
 * Switching can be triggered programmatically, via remote control (UDP),
 * or by an external shell command.
 *
 * Usage:
 *   $sw = new InputSwitcher();
 *   $sw->setPlugins('-I', 'file', 'input1.ts', '-I', 'file', 'input2.ts', '-O', 'file', 'output.ts');
 *   $sw->start();
 *   // ... later ...
 *   $sw->nextInput();
 *   $sw->waitForTermination();
 *   $sw->close();
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class InputSwitcher extends PluginEventHandlerRegistry
{
    /**
     * Fast switch between input plugins.
     */
    public bool $fastSwitch = false;

    /**
     * Delayed switch between input plugins.
     */
    public bool $delayedSwitch = false;

    /**
     * Terminate when one input plugin completes.
     */
    public bool $terminate = false;

    /**
     * Reuse-port socket option.
     */
    public bool $reusePort = false;

    /**
     * Index of the first input plugin.
     */
    public int $firstInput = 0;

    /**
     * Index of the primary input plugin, negative if there is none.
     */
    public int $primaryInput = -1;

    /**
     * Number of input cycles to execute (0 = infinite).
     */
    public int $cycleCount = 1;

    /**
     * Input buffer size in packets (0 = default).
     */
    public int $bufferedPackets = 0;

    /**
     * Maximum input packets to read at a time (0 = default).
     */
    public int $maxInputPackets = 0;

    /**
     * Maximum output packets to send at a time (0 = default).
     */
    public int $maxOutputPackets = 0;

    /**
     * Socket buffer size in bytes (0 = default).
     */
    public int $sockBuffer = 0;

    /**
     * UDP server port for remote control (0 = none).
     */
    public int $remoteServerPort = 0;

    /**
     * Receive timeout in milliseconds before switch (0 = none).
     */
    public int $receiveTimeout = 0;

    /**
     * Remote UDP port to receive switching event JSON description.
     */
    public int $eventUdpPort = 0;

    /**
     * Time-to-live socket option for event UDP.
     */
    public int $eventTtl = 0;

    /**
     * The UTF-16 LE plugin specification buffer.
     */
    private ?InBuffer $pluginsBuffer = null;

    /**
     * The UTF-16 LE event command buffer.
     */
    private ?InBuffer $eventCommandBuffer = null;

    /**
     * The UTF-16 LE event UDP address buffer.
     */
    private ?InBuffer $eventUdpAddressBuffer = null;

    /**
     * The UTF-16 LE local address buffer for event UDP.
     */
    private ?InBuffer $localAddressBuffer = null;

    /**
     * Creates a new InputSwitcher.
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

        // void* tspyNewInputSwitcher(void* report)
        $pointer = $ffi->tspyNewInputSwitcher($reportPointer);

        parent::__construct($ffi, $pointer);
    }

    /**
     * Sets the plugin specification for the input switcher.
     *
     * Each argument is a plugin specification element. Use '-I' to start
     * an input plugin and '-O' for an output plugin. The remaining
     * arguments for each plugin follow until the next marker.
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
     * Sets the external shell command to run on a switching event.
     *
     * The command is executed each time the input switcher switches
     * to a different input.
     *
     * This method returns $this for fluent chaining.
     *
     * @param string ...$commands The shell command and its arguments
     *
     * @return $this This instance for fluent chaining
     */
    public function setEventCommand(string ...$commands): self
    {
        $this->eventCommandBuffer = new InBuffer($this->ffi);
        foreach ($commands as $cmd) {
            $this->eventCommandBuffer->append($cmd);
        }

        return $this;
    }

    /**
     * Sets the remote IPv4 address or hostname to receive switching event JSON.
     *
     * @param string $address The UDP address (IPv4 or hostname)
     *
     * @return $this This instance for fluent chaining
     */
    public function setEventUdpAddress(string $address): self
    {
        $this->eventUdpAddressBuffer = new InBuffer($this->ffi);
        $this->eventUdpAddressBuffer->append($address);

        return $this;
    }

    /**
     * Sets the outgoing local interface for event UDP.
     *
     * @param string $address The local interface address
     *
     * @return $this This instance for fluent chaining
     */
    public function setLocalAddress(string $address): self
    {
        $this->localAddressBuffer = new InBuffer($this->ffi);
        $this->localAddressBuffer->append($address);

        return $this;
    }

    /**
     * Starts the input switcher.
     *
     * Builds the native tspyInputSwitcherArgs struct from the current property
     * values and all configured buffers, then calls tspyStartInputSwitcher.
     *
     * @throws SwitchStartException If the switcher fails to start
     */
    public function start(): void
    {
        $this->assertNotClosed();

        $args = $this->ffi->new('struct tspyInputSwitcherArgs', false, false);

        StructMapper::set($args, 'fast_switch', $this->fastSwitch);
        StructMapper::set($args, 'delayed_switch', $this->delayedSwitch);
        StructMapper::set($args, 'terminate', $this->terminate);
        StructMapper::set($args, 'reuse_port', $this->reusePort);
        StructMapper::set($args, 'first_input', $this->firstInput);
        StructMapper::set($args, 'primary_input', $this->primaryInput);
        StructMapper::set($args, 'cycle_count', $this->cycleCount);
        StructMapper::set($args, 'buffered_packets', $this->bufferedPackets);
        StructMapper::set($args, 'max_input_packets', $this->maxInputPackets);
        StructMapper::set($args, 'max_output_packets', $this->maxOutputPackets);
        StructMapper::set($args, 'sock_buffer', $this->sockBuffer);
        StructMapper::set($args, 'remote_server_port', $this->remoteServerPort);
        StructMapper::set($args, 'receive_timeout', $this->receiveTimeout);
        StructMapper::set($args, 'event_udp_port', $this->eventUdpPort);
        StructMapper::set($args, 'event_ttl', $this->eventTtl);

        // Event command buffer.
        $eventCmdBuf = $this->eventCommandBuffer ?? new InBuffer($this->ffi);
        StructMapper::setString($args, 'event_command', 'event_command_size', $eventCmdBuf);

        // Event UDP address buffer.
        $eventUdpBuf = $this->eventUdpAddressBuffer ?? new InBuffer($this->ffi);
        StructMapper::setString($args, 'event_udp_addr', 'event_udp_addr_size', $eventUdpBuf);

        // Local address buffer.
        $localAddrBuf = $this->localAddressBuffer ?? new InBuffer($this->ffi);
        StructMapper::setString($args, 'local_addr', 'local_addr_size', $localAddrBuf);

        // Plugin specification buffer.
        $pluginsBuf = $this->pluginsBuffer ?? new InBuffer($this->ffi);
        StructMapper::setString($args, 'plugins', 'plugins_size', $pluginsBuf);

        // int tspyStartInputSwitcher(void* pyobj, const struct tspyInputSwitcherArgs* pyargs)
        $result = (int) $this->ffi->tspyStartInputSwitcher(
            $this->getPointer(),
            FFI::addr($args),
        );

        if ($result === 0) {
            throw new SwitchStartException('Error starting input switcher');
        }
    }

    /**
     * Switches to the specified input plugin by index.
     *
     * @param int $index The zero-based index of the input plugin to switch to
     */
    public function setInput(int $index): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyInputSwitcherSetInput($this->getPointer(), $index);
    }

    /**
     * Switches to the next input plugin.
     *
     * Wraps around to the first input if currently on the last one.
     */
    public function nextInput(): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyInputSwitcherNextInput($this->getPointer());
    }

    /**
     * Switches to the previous input plugin.
     *
     * Wraps around to the last input if currently on the first one.
     */
    public function previousInput(): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyInputSwitcherPreviousInput($this->getPointer());
    }

    /**
     * Returns the index of the current input plugin.
     *
     * @return int The zero-based index of the currently active input plugin
     */
    public function currentInput(): int
    {
        $this->assertNotClosed();

        return (int) $this->ffi->tspyInputSwitcherCurrentInput($this->getPointer());
    }

    /**
     * Terminates the input switcher processing.
     *
     * Requests the switcher to stop. This method returns immediately;
     * use waitForTermination() to wait until the switcher has fully stopped.
     */
    public function stop(): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyStopInputSwitcher($this->getPointer());
    }

    /**
     * Suspends the calling thread until input switcher processing is completed.
     *
     * Blocks until the switcher has finished and all plugins have terminated.
     */
    public function waitForTermination(): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyWaitInputSwitcher($this->getPointer());
    }

    /**
     * Frees the underlying C++ InputSwitcher object.
     */
    protected function doClose(): void
    {
        $pointer = $this->getPointer();
        if ($pointer !== null) {
            $this->ffi->tspyDeleteInputSwitcher($pointer);
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
