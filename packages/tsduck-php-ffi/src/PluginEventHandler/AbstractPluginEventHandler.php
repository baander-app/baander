<?php

declare(strict_types=1);

namespace Tsduck\PluginEventHandler;

use FFI;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\Util\NativeObject;

/**
 * A polling-based plugin event handler for receiving events from TS processing threads.
 *
 * This class extends NativeObject and uses a polling pattern instead of direct
 * FFI callbacks to safely receive plugin events from TS processing threads.
 * PHP's FFI closures are NOT safe to invoke from non-PHP threads (no GIL
 * equivalent), so this class polls a thread-safe event queue that the C++
 * PollingPluginEventHandler bridge populates.
 *
 * CRITICAL: After receiving an event via waitForEvents(), you MUST call
 * completeEvent() with the event's eventId. The C++ bridge blocks the TS
 * plugin thread on a std::promise/std::future until completeEvent() is called.
 * Failure to call completeEvent() will permanently deadlock the TS pipeline.
 *
 * Usage:
 *   class MyEventHandler extends AbstractPluginEventHandler
 *   {
 *       public function handlePluginEvent(PluginEventContext $context): void
 *       {
 *           echo "Event {$context->eventCode} from {$context->pluginName}\n";
 *           $this->completeEvent($context->eventId, true);
 *       }
 *   }
 *
 *   $handler = new MyEventHandler();
 *   $registry->registerEventHandler($handler, 0);
 *   // ... run TSProcessor ...
 *   $handler->close();
 *
 * Thread safety: This class IS safe for use with TS processing threads.
 * The polling pattern ensures all event processing happens on the PHP thread.
 *
 * @see PluginEventContext The data class for event information
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
abstract class AbstractPluginEventHandler extends NativeObject
{
    /**
     * Creates a new polling-based plugin event handler.
     *
     * The constructor calls tspyphpNewPollingPluginEventHandler which creates
     * a C++ PollingPluginEventHandler that queues events into a thread-safe
     * queue and blocks on a per-event std::promise until PHP responds.
     *
     * IMPORTANT: This class requires 64-bit PHP (PHP_INT_SIZE >= 8). The C++
     * event ID is a uint64_t which exceeds the range of 32-bit PHP integers.
     * On 32-bit PHP, the upper 32 bits of the event ID would be silently
     * truncated, causing completeEvent() to complete the wrong event and
     * deadlock the TS pipeline.
     *
     * @param int $maxQueueSize Maximum number of events in the polling queue
     *                          (default: 1024)
     *
     * @throws TsduckException If running on a 32-bit PHP build
     */
    public function __construct(int $maxQueueSize = 1024)
    {
        if (PHP_INT_SIZE < 8) {
            throw new TsduckException(
                '64-bit PHP is required for PluginEventHandler. '
                . 'The C++ event ID is a uint64_t which exceeds the range of '
                . '32-bit PHP integers, causing silent truncation and pipeline deadlocks.',
            );
        }

        $ffi = LibTSDuck::getInstance();

        // tspyphpNewPollingPluginEventHandler(size_t max_queue_size) -> void*
        $pointer = $ffi->tspyphpNewPollingPluginEventHandler($maxQueueSize);

        parent::__construct($ffi, $pointer);
    }

    /**
     * Frees the underlying C++ PollingPluginEventHandler object.
     *
     * Any pending events that were never completed by PHP will have their
     * shared_ptr<promise> dropped. The TS plugin threads waiting on the
     * associated futures will remain blocked, but this destructor is only
     * called after the TS pipeline is terminated.
     */
    protected function doClose(): void
    {
        $pointer = $this->getPointer();
        if ($pointer !== null) {
            $this->ffi->tspyphpDeletePollingPluginEventHandler($pointer);
        }
    }

    /**
     * Polls for queued plugin events, blocking up to the specified timeout.
     *
     * Returns an array of PluginEventContext objects. Each context contains
     * a unique eventId that MUST be passed to completeEvent() after processing.
     *
     * The C++ bridge returns events one at a time (not batched). Each call
     * to waitForEvents() returns either zero or one event.
     *
     * Timeout semantics:
     *   - 0: Non-blocking poll (returns immediately)
     *   - -1: Block forever until at least one event is available
     *   - N > 0: Block up to N milliseconds
     *
     * @param int $timeoutMs Maximum time to wait in milliseconds
     *                       (default: 1000)
     *
     * @return list<PluginEventContext> Array of event contexts (usually 0 or 1)
     *
     * @throws TsduckException If the object has been closed
     */
    public function waitForEvents(int $timeoutMs = 1000): array
    {
        $this->assertNotClosed();

        // Pre-allocate a large buffer (same pattern as AbstractAsyncReport::waitForMessages).
        // The C wrapper tspyphpPollPluginEvents short-circuits when buffer is null,
        // so we must never pass null — events would never be dequeued and the
        // TS pipeline would deadlock permanently.
        $bufferSize = 65536;
        $buffer = $this->ffi->new("uint8_t[{$bufferSize}]", false, false);
        $sizePtr = $this->ffi->new('size_t', false, false);
        $sizePtr->cdata = $bufferSize;

        // tspyphpPollPluginEvents(void* handler, uint8_t* buffer, size_t* buffer_size, int timeout_ms) -> int
        $result = $this->ffi->tspyphpPollPluginEvents(
            $this->getPointer(),
            $buffer,
            FFI::addr($sizePtr),
            $timeoutMs,
        );

        if (!$result) {
            // No events available (timeout or non-blocking poll).
            return [];
        }

        // Check if the buffer was too small. If so, resize and retry once.
        if ((int) $sizePtr->cdata > $bufferSize) {
            $bufferSize = (int) $sizePtr->cdata;
            $buffer = $this->ffi->new("uint8_t[{$bufferSize}]", false, false);
            $sizePtr->cdata = $bufferSize;

            $result = $this->ffi->tspyphpPollPluginEvents(
                $this->getPointer(),
                $buffer,
                FFI::addr($sizePtr),
                0, // Non-blocking: the event is already dequeued and held by C++
            );

            if (!$result) {
                return [];
            }
        }

        // Parse the binary event data into a PluginEventContext.
        $bytes = FFI::string($buffer, (int) $sizePtr->cdata);

        return [$this->parseEventData($bytes)];
    }

    /**
     * Completes a plugin event, fulfilling the std::promise that the TS plugin
     * thread is waiting on.
     *
     * CRITICAL: This method MUST be called for every event received via
     * waitForEvents(). The C++ handlePluginEvent() blocks on a per-event
     * std::future until this method is called. Failure to call completeEvent()
     * will permanently deadlock the TS pipeline.
     *
     * If $data is provided and the event data is modifiable (not read-only),
     * the data is copied back to the C++ PluginEventData buffer. The size
     * of the data must not exceed the context's maxDataSize.
     *
     * @param int          $eventId The unique event identifier from PluginEventContext
     * @param bool         $success True if the event was handled successfully,
     *                               false to set the error indicator
     * @param string|null  $data    Modified event data to return to the plugin,
     *                               or null if no data modification (default: null)
     *
     * @throws TsduckException If the object has been closed
     */
    public function completeEvent(int $eventId, bool $success = true, ?string $data = null): void
    {
        $this->assertNotClosed();

        if ($data !== null && $data !== '') {
            // Allocate a C buffer for the data.
            $dataLen = strlen($data);
            $cData = $this->ffi->new("uint8_t[{$dataLen}]", false, false);
            for ($i = 0; $i < $dataLen; $i++) {
                $cData[$i] = ord($data[$i]);
            }

            // tspyphpCompletePluginEvent(void* handler, uint64_t event_id, int success, const uint8_t* data, size_t data_size)
            $this->ffi->tspyphpCompletePluginEvent(
                $this->getPointer(),
                $eventId,
                $success ? 1 : 0,
                $cData,
                $dataLen,
            );
        } else {
            // No data to return.
            $this->ffi->tspyphpCompletePluginEvent(
                $this->getPointer(),
                $eventId,
                $success ? 1 : 0,
                null,
                0,
            );
        }
    }

    /**
     * Handles a plugin event received from the polling queue.
     *
     * Override this method in subclasses to process plugin events. The
     * default implementation completes the event with success=true and
     * no data modification.
     *
     * IMPORTANT: Your override MUST call completeEvent() with the context's
     * eventId. If you do not, the TS pipeline will deadlock. The default
     * implementation handles this for you.
     *
     * @param PluginEventContext $context The event context with all event details
     */
    public function handlePluginEvent(PluginEventContext $context): void
    {
        // Default: complete the event with success and no data modification.
        $this->completeEvent($context->eventId, true, null);
    }

    /**
     * Runs a poll loop that continuously receives and handles events.
     *
     * This method blocks, calling handlePluginEvent() for each event
     * received. It runs until the handler is closed (via close() or
     * the object going out of scope).
     *
     * This is a convenience method for simple use cases. For more control
     * over the poll loop, use waitForEvents() directly.
     *
     * @param int $timeoutMs Maximum time to wait per poll iteration
     *                       (default: 1000)
     */
    public function run(int $timeoutMs = 1000): void
    {
        while (!$this->isClosed()) {
            $events = $this->waitForEvents($timeoutMs);
            foreach ($events as $context) {
                $completed = false;
                try {
                    $this->handlePluginEvent($context);
                } catch (\Throwable $e) {
                    // User's handlePluginEvent() threw — complete with failure
                    // to unblock the TS plugin thread, then re-throw.
                    if (!$completed && !$this->isClosed()) {
                        $this->completeEvent($context->eventId, false);
                        $completed = true;
                    }
                    throw $e;
                } finally {
                    // Safety net: ensure completeEvent is called even if the
                    // user's handlePluginEvent() forgot to call it.
                    // The default handlePluginEvent() does call completeEvent(),
                    // but user overrides may not.
                    if (!$completed && !$this->isClosed()) {
                        $this->completeEvent($context->eventId, true);
                    }
                }
            }
        }
    }

    /**
     * Parses the binary event data from the C++ bridge into a PluginEventContext.
     *
     * The serialization format from C++ (little-endian):
     *   [event_id:      8 bytes, uint64_t]
     *   [event_code:    4 bytes, uint32_t]
     *   [plugin_index:  8 bytes, uint64_t]
     *   [plugin_count:  8 bytes, uint64_t]
     *   [bitrate:       8 bytes, uint64_t]
     *   [plugin_packets:8 bytes, uint64_t]
     *   [total_packets: 8 bytes, uint64_t]
     *   [data_size:     8 bytes, uint64_t]
     *   [data_max_size: 8 bytes, uint64_t]
     *   [data_read_only:1 byte,  uint8_t]
     *   [plugin_name:   N bytes, UTF-16 LE]
     *   [data:          M bytes, raw binary]
     *
     * @param string $bytes The raw binary data from the C++ bridge
     *
     * @return PluginEventContext The parsed event context
     */
    private function parseEventData(string $bytes): PluginEventContext
    {
        $offset = 0;

        $eventId      = self::readUInt64LE($bytes, $offset); $offset += 8;
        $eventCode    = self::readUInt32LE($bytes, $offset); $offset += 4;
        $pluginIndex  = self::readUInt64LE($bytes, $offset); $offset += 8;
        $pluginCount  = self::readUInt64LE($bytes, $offset); $offset += 8;
        $bitrate      = self::readUInt64LE($bytes, $offset); $offset += 8;
        $pluginPackets = self::readUInt64LE($bytes, $offset); $offset += 8;
        $totalPackets = self::readUInt64LE($bytes, $offset); $offset += 8;
        $dataSize     = self::readUInt64LE($bytes, $offset); $offset += 8;
        $maxDataSize  = self::readUInt64LE($bytes, $offset); $offset += 8;

        $dataReadOnly = (ord($bytes[$offset]) === 1);
        $offset += 1;

        // Read plugin_name (UTF-16 LE, remaining before data section).
        // The name length is: (total - fixed_header - data_size) bytes.
        $totalLen = strlen($bytes);
        $fixedHeaderSize = 8 + 4 + 8 + 8 + 8 + 8 + 8 + 8 + 8 + 1; // 69 bytes
        $nameBytes = $totalLen - $fixedHeaderSize - $dataSize;
        $pluginName = '';
        if ($nameBytes > 0) {
            $nameRaw = substr($bytes, $offset, $nameBytes);
            $pluginName = mb_convert_encoding($nameRaw, 'UTF-8', 'UTF-16LE');
            $offset += $nameBytes;
        }

        // Read event data (raw binary).
        $data = null;
        if ($dataSize > 0) {
            $data = substr($bytes, $offset, $dataSize);
        }

        return new PluginEventContext(
            eventId: $eventId,
            eventCode: $eventCode,
            pluginName: $pluginName,
            pluginIndex: $pluginIndex,
            pluginCount: $pluginCount,
            bitrate: $bitrate,
            pluginPackets: $pluginPackets,
            totalPackets: $totalPackets,
            data: $data,
            dataSize: $dataSize,
            maxDataSize: $maxDataSize,
            dataReadOnly: $dataReadOnly,
        );
    }

    /**
     * Reads a little-endian unsigned 64-bit integer from a byte string.
     *
     * @param string $bytes The byte string
     * @param int    $offset The byte offset to read from
     *
     * @return int The unsigned 64-bit integer value
     */
    private static function readUInt64LE(string $bytes, int $offset): int
    {
        // PHP integers may be 32-bit on 32-bit platforms. Use unpack for safety.
        // On 64-bit platforms, this works directly. On 32-bit, values > PHP_INT_MAX
        // would be truncated, but TSDuck packet counts are unlikely to exceed that.
        $low = unpack('V', substr($bytes, $offset, 4))[1];
        $high = unpack('V', substr($bytes, $offset + 4, 4))[1];

        return ($high << 32) | $low;
    }

    /**
     * Reads a little-endian unsigned 32-bit integer from a byte string.
     *
     * @param string $bytes The byte string
     * @param int    $offset The byte offset to read from
     *
     * @return int The unsigned 32-bit integer value
     */
    private static function readUInt32LE(string $bytes, int $offset): int
    {
        return unpack('V', substr($bytes, $offset, 4))[1];
    }
}
