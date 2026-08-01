<?php

declare(strict_types=1);

namespace Tsduck\PluginEventHandler;

/**
 * Data class containing the details of a plugin event.
 *
 * Each event polled from AbstractPluginEventHandler::waitForEvents()
 * returns a PluginEventContext with information about the originating
 * plugin, event code, bitrate, packet counts, and optional binary data.
 *
 * The eventId uniquely identifies this event and MUST be passed to
 * AbstractPluginEventHandler::completeEvent() after processing to
 * unblock the TS plugin thread that generated the event. Failure to
 * call completeEvent() will deadlock the TS pipeline.
 *
 * NOTE: The C++ event ID is a uint64_t. On 64-bit PHP (PHP_INT_SIZE >= 8),
 * PHP int is 64-bit and can represent the full range. On 32-bit PHP,
 * this value would be silently truncated. AbstractPluginEventHandler's
 * constructor enforces the 64-bit requirement at runtime.
 *
 * Properties are readonly -- this is a pure data transfer object.
 *
 * @see AbstractPluginEventHandler::waitForEvents()
 * @see AbstractPluginEventHandler::completeEvent()
 */
final class PluginEventContext
{
    /**
     * Creates a new PluginEventContext with all fields.
     *
     * @param int          $eventId       Unique event identifier (must be passed to completeEvent())
     * @param int          $eventCode     Plugin-defined 32-bit code describing the event type
     * @param string       $pluginName    Plugin name as found in the plugin registry
     * @param int          $pluginIndex   Plugin index in the chain (0-based)
     * @param int          $pluginCount   Total number of plugins in the chain
     * @param int          $bitrate       Known bitrate in b/s at the time of the event
     * @param int          $pluginPackets Number of packets which passed through the plugin
     * @param int          $totalPackets  Total packets in the plugin thread at event time
     * @param string|null  $data          Binary data associated with the event, or null
     * @param int          $dataSize      Size of the event data in bytes
     * @param int          $maxDataSize   Maximum size for output data (0 if read-only)
     * @param bool         $dataReadOnly  Whether the event data is read-only
     */
    public function __construct(
        public readonly int $eventId,
        public readonly int $eventCode,
        public readonly string $pluginName,
        public readonly int $pluginIndex,
        public readonly int $pluginCount,
        public readonly int $bitrate,
        public readonly int $pluginPackets,
        public readonly int $totalPackets,
        public readonly ?string $data,
        public readonly int $dataSize,
        public readonly int $maxDataSize,
        public readonly bool $dataReadOnly,
    ) {
    }
}
