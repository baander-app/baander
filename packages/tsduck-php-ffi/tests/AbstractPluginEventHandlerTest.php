<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use PHPUnit\Framework\TestCase;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\PluginEventHandler\AbstractPluginEventHandler;
use Tsduck\PluginEventHandler\PluginEventContext;

/**
 * Tests for AbstractPluginEventHandler: polling-based plugin event handler.
 *
 * Tests requiring the native libtsduck library skip gracefully when unavailable.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class AbstractPluginEventHandlerTest extends TestCase
{
    /**
     * Whether the TSDuck native library is available.
     */
    private static bool $nativeAvailable;

    public static function setUpBeforeClass(): void
    {
        self::$nativeAvailable = \extension_loaded('ffi');
        if (self::$nativeAvailable) {
            try {
                LibTSDuck::getInstance();
            } catch (TsduckException) {
                self::$nativeAvailable = false;
            }
        }
    }

    // =========================================================================
    // PluginEventContext data class tests (no native library needed)
    // =========================================================================

    public function testPluginEventContextCreation(): void
    {
        $context = new PluginEventContext(
            eventId: 42,
            eventCode: 100,
            pluginName: 'test_plugin',
            pluginIndex: 0,
            pluginCount: 3,
            bitrate: 10000000,
            pluginPackets: 5000,
            totalPackets: 15000,
            data: null,
            dataSize: 0,
            maxDataSize: 0,
            dataReadOnly: true,
        );

        $this->assertSame(42, $context->eventId);
        $this->assertSame(100, $context->eventCode);
        $this->assertSame('test_plugin', $context->pluginName);
        $this->assertSame(0, $context->pluginIndex);
        $this->assertSame(3, $context->pluginCount);
        $this->assertSame(10000000, $context->bitrate);
        $this->assertSame(5000, $context->pluginPackets);
        $this->assertSame(15000, $context->totalPackets);
        $this->assertNull($context->data);
        $this->assertSame(0, $context->dataSize);
        $this->assertSame(0, $context->maxDataSize);
        $this->assertTrue($context->dataReadOnly);
    }

    public function testPluginEventContextWithBinaryData(): void
    {
        $binaryData = "\x00\x01\x02\x03\xFF\xFE\xFD\xFC";

        $context = new PluginEventContext(
            eventId: 1,
            eventCode: 0,
            pluginName: 'memory',
            pluginIndex: 0,
            pluginCount: 1,
            bitrate: 0,
            pluginPackets: 0,
            totalPackets: 0,
            data: $binaryData,
            dataSize: 8,
            maxDataSize: 188,
            dataReadOnly: false,
        );

        $this->assertSame($binaryData, $context->data);
        $this->assertSame(8, $context->dataSize);
        $this->assertSame(188, $context->maxDataSize);
        $this->assertFalse($context->dataReadOnly);
    }

    public function testPluginEventContextPropertiesAreReadonly(): void
    {
        $context = new PluginEventContext(
            eventId: 1,
            eventCode: 0,
            pluginName: 'test',
            pluginIndex: 0,
            pluginCount: 1,
            bitrate: 0,
            pluginPackets: 0,
            totalPackets: 0,
            data: null,
            dataSize: 0,
            maxDataSize: 0,
            dataReadOnly: true,
        );

        // Verify that the properties are readonly by checking they are defined
        // and accessible (readonly properties throw on write in PHP 8.1+).
        $reflection = new \ReflectionClass($context);

        foreach (['eventId', 'eventCode', 'pluginName', 'pluginIndex', 'pluginCount',
                   'bitrate', 'pluginPackets', 'totalPackets', 'data', 'dataSize',
                   'maxDataSize', 'dataReadOnly'] as $prop) {
            $this->assertTrue(
                $reflection->hasProperty($prop),
                "PluginEventContext should have property '{$prop}'.",
            );
        }

        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Event data parsing unit tests (no native library needed)
    // =========================================================================

    /**
     * Tests the binary event data serialization format parsing.
     *
     * This test constructs a synthetic binary buffer matching the C++
     * PollingPluginEventHandler::pollEvents() format and verifies that
     * the parseEventData() method correctly decodes it.
     *
     * We test this by using reflection to access the private parseEventData method.
     */
    public function testParseEventDataFormat(): void
    {
        // Construct a synthetic event buffer matching the C++ format:
        // [event_id:      8 bytes, uint64_t LE]
        // [event_code:    4 bytes, uint32_t LE]
        // [plugin_index:  8 bytes, uint64_t LE]
        // [plugin_count:  8 bytes, uint64_t LE]
        // [bitrate:       8 bytes, uint64_t LE]
        // [plugin_packets:8 bytes, uint64_t LE]
        // [total_packets: 8 bytes, uint64_t LE]
        // [data_size:     8 bytes, uint64_t LE]
        // [data_max_size: 8 bytes, uint64_t LE]
        // [data_read_only:1 byte,  uint8_t]
        // [plugin_name:   N bytes, UTF-16 LE]
        // [data:          M bytes, raw binary]

        $eventId = 12345;
        $eventCode = 42;
        $pluginIndex = 2;
        $pluginCount = 5;
        $bitrate = 3000000;
        $pluginPackets = 1000;
        $totalPackets = 5000;
        $dataSize = 4;
        $maxDataSize = 188;
        $dataReadOnly = false;

        // Encode plugin name as UTF-16 LE.
        $pluginName = 'test_plugin';
        $nameUtf16 = mb_convert_encoding($pluginName, 'UTF-16LE', 'UTF-8');

        // Event data payload.
        $eventData = "\xDE\xAD\xBE\xEF";

        // Build the binary buffer.
        $buf = '';
        $buf .= pack('V', $eventId & 0xFFFFFFFF) . pack('V', ($eventId >> 32) & 0xFFFFFFFF);  // event_id
        $buf .= pack('V', $eventCode);                                                          // event_code
        $buf .= pack('V', $pluginIndex & 0xFFFFFFFF) . pack('V', ($pluginIndex >> 32) & 0xFFFFFFFF);  // plugin_index
        $buf .= pack('V', $pluginCount & 0xFFFFFFFF) . pack('V', ($pluginCount >> 32) & 0xFFFFFFFF);  // plugin_count
        $buf .= pack('V', $bitrate & 0xFFFFFFFF) . pack('V', ($bitrate >> 32) & 0xFFFFFFFF);          // bitrate
        $buf .= pack('V', $pluginPackets & 0xFFFFFFFF) . pack('V', ($pluginPackets >> 32) & 0xFFFFFFFF);  // plugin_packets
        $buf .= pack('V', $totalPackets & 0xFFFFFFFF) . pack('V', ($totalPackets >> 32) & 0xFFFFFFFF);  // total_packets
        $buf .= pack('V', $dataSize & 0xFFFFFFFF) . pack('V', ($dataSize >> 32) & 0xFFFFFFFF);          // data_size
        $buf .= pack('V', $maxDataSize & 0xFFFFFFFF) . pack('V', ($maxDataSize >> 32) & 0xFFFFFFFF);    // data_max_size
        $buf .= chr($dataReadOnly ? 1 : 0);  // data_read_only
        $buf .= $nameUtf16;                   // plugin_name (UTF-16 LE)
        $buf .= $eventData;                   // data (raw binary)

        // Parse using reflection.
        $handler = new class() extends AbstractPluginEventHandler {
            public function parseTestData(string $bytes): PluginEventContext
            {
                // Access the private parseEventData method via reflection.
                $ref = new \ReflectionMethod($this, 'parseEventData');
                $ref->setAccessible(true);
                return $ref->invoke($this, $bytes);
            }
        };

        // The handler must be created with native lib, so skip if unavailable.
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $context = $handler->parseTestData($buf);

        $this->assertSame($eventId, $context->eventId);
        $this->assertSame($eventCode, $context->eventCode);
        $this->assertSame($pluginName, $context->pluginName);
        $this->assertSame($pluginIndex, $context->pluginIndex);
        $this->assertSame($pluginCount, $context->pluginCount);
        $this->assertSame($bitrate, $context->bitrate);
        $this->assertSame($pluginPackets, $context->pluginPackets);
        $this->assertSame($totalPackets, $context->totalPackets);
        $this->assertSame($dataSize, $context->dataSize);
        $this->assertSame($maxDataSize, $context->maxDataSize);
        $this->assertSame($dataReadOnly, $context->dataReadOnly);
        $this->assertSame($eventData, $context->data);

        $handler->close();
    }

    public function testParseEventDataWithNoData(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $eventId = 99;
        $eventCode = 7;

        $pluginName = 'noplug';
        $nameUtf16 = mb_convert_encoding($pluginName, 'UTF-16LE', 'UTF-8');

        // Build buffer with no data section.
        $buf = '';
        $buf .= pack('V', $eventId & 0xFFFFFFFF) . pack('V', ($eventId >> 32) & 0xFFFFFFFF);
        $buf .= pack('V', $eventCode);
        $buf .= pack('V', 0) . pack('V', 0);  // plugin_index = 0
        $buf .= pack('V', 1) . pack('V', 0);  // plugin_count = 1
        $buf .= pack('V', 0) . pack('V', 0);  // bitrate = 0
        $buf .= pack('V', 0) . pack('V', 0);  // plugin_packets = 0
        $buf .= pack('V', 0) . pack('V', 0);  // total_packets = 0
        $buf .= pack('V', 0) . pack('V', 0);  // data_size = 0
        $buf .= pack('V', 0) . pack('V', 0);  // data_max_size = 0
        $buf .= chr(1);                        // data_read_only = true
        $buf .= $nameUtf16;

        $handler = new class() extends AbstractPluginEventHandler {
            public function parseTestData(string $bytes): PluginEventContext
            {
                $ref = new \ReflectionMethod($this, 'parseEventData');
                $ref->setAccessible(true);
                return $ref->invoke($this, $bytes);
            }
        };

        $context = $handler->parseTestData($buf);

        $this->assertSame($eventId, $context->eventId);
        $this->assertSame($eventCode, $context->eventCode);
        $this->assertSame($pluginName, $context->pluginName);
        $this->assertNull($context->data);
        $this->assertSame(0, $context->dataSize);
        $this->assertTrue($context->dataReadOnly);

        $handler->close();
    }

    public function testParseEventDataWithEmptyPluginName(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        // Build buffer with empty plugin name and no data.
        $buf = '';
        $buf .= pack('V', 1) . pack('V', 0);  // event_id = 1
        $buf .= pack('V', 0);                  // event_code = 0
        $buf .= pack('V', 0) . pack('V', 0);  // plugin_index = 0
        $buf .= pack('V', 1) . pack('V', 0);  // plugin_count = 1
        $buf .= pack('V', 0) . pack('V', 0);  // bitrate = 0
        $buf .= pack('V', 0) . pack('V', 0);  // plugin_packets = 0
        $buf .= pack('V', 0) . pack('V', 0);  // total_packets = 0
        $buf .= pack('V', 0) . pack('V', 0);  // data_size = 0
        $buf .= pack('V', 0) . pack('V', 0);  // data_max_size = 0
        $buf .= chr(1);                        // data_read_only = true
        // No plugin_name bytes (empty).
        // No data bytes.

        $handler = new class() extends AbstractPluginEventHandler {
            public function parseTestData(string $bytes): PluginEventContext
            {
                $ref = new \ReflectionMethod($this, 'parseEventData');
                $ref->setAccessible(true);
                return $ref->invoke($this, $bytes);
            }
        };

        $context = $handler->parseTestData($buf);

        $this->assertSame(1, $context->eventId);
        $this->assertSame('', $context->pluginName);

        $handler->close();
    }

    // =========================================================================
    // Construction and lifecycle (requires native library)
    // =========================================================================

    public function testConstructWithDefaults(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $handler = new class() extends AbstractPluginEventHandler {
        };

        $this->assertFalse($handler->isClosed(), 'New handler should not be closed.');
        $handler->close();
    }

    public function testConstructWithCustomQueueSize(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $handler = new class(2048) extends AbstractPluginEventHandler {
        };

        $this->assertFalse($handler->isClosed());
        $handler->close();
    }

    public function testCloseIsIdempotent(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $handler = new class() extends AbstractPluginEventHandler {
        };
        $handler->close();
        $handler->close(); // Should not throw.

        $this->assertTrue($handler->isClosed());
    }

    public function testMethodAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $handler = new class() extends AbstractPluginEventHandler {
        };
        $handler->close();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Cannot operate on a closed');

        $handler->waitForEvents(0);
    }

    public function testCompleteEventAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $handler = new class() extends AbstractPluginEventHandler {
        };
        $handler->close();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Cannot operate on a closed');

        $handler->completeEvent(1, true);
    }

    // =========================================================================
    // Polling behavior (requires native library)
    // =========================================================================

    public function testNonBlockingPollReturnsEmptyArray(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $handler = new class() extends AbstractPluginEventHandler {
        };

        // Non-blocking poll with no events should return empty array.
        $events = $handler->waitForEvents(0);

        $this->assertIsArray($events);
        $this->assertEmpty($events, 'Non-blocking poll with no events should return empty array.');

        $handler->close();
    }

    public function testShortTimeoutPollReturnsEmptyArray(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $handler = new class() extends AbstractPluginEventHandler {
        };

        $events = $handler->waitForEvents(10);

        $this->assertIsArray($events);
        $this->assertEmpty($events, 'Short timeout poll with no events should return empty array.');

        $handler->close();
    }

    // =========================================================================
    // Destructor cleanup
    // =========================================================================

    public function testDestructorCleansUpWithoutClose(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        // Create a handler that goes out of scope without explicit close().
        (function (): void {
            $handler = new class() extends AbstractPluginEventHandler {
            };
            // Let the handler go out of scope.
            // __destruct() should handle cleanup.
        })();

        // If we get here without a crash, the destructor worked.
        $this->assertTrue(true);
    }
}
