<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use PHPUnit\Framework\TestCase;
use Tsduck\Exception\SwitchStartException;
use Tsduck\Exception\TSPStartException;
use Tsduck\Exception\TsduckException;
use Tsduck\FFI\LibTSDuck;
use Tsduck\InputSwitcher;
use Tsduck\PluginEventHandlerRegistry;
use Tsduck\Report\NullReport;
use Tsduck\TSProcessor;
use Tsduck\Util\StructMapper;
use Tsduck\Util\InBuffer;

/**
 * Tests for TSProcessor, InputSwitcher, PluginEventHandlerRegistry, and StructMapper.
 *
 * Tests requiring the native libtsduck library skip gracefully when unavailable.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class TSProcessorTest extends TestCase
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
            } catch (TsduckException $e) {
                self::$nativeAvailable = false;
            }
        }
    }

    protected function setUp(): void
    {
        if (self::$nativeAvailable) {
            NullReport::reset();
        }
    }

    // =========================================================================
    // TSProcessor - Constructor
    // =========================================================================

    public function testConstructWithDefaultNullReport(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();

        $this->assertFalse($tsp->isClosed(), 'TSProcessor should not be closed after construction.');
        $this->assertInstanceOf(PluginEventHandlerRegistry::class, $tsp);
        $tsp->close();
    }

    public function testConstructWithExplicitNullReport(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $report = NullReport::getInstance();
        $tsp = new TSProcessor($report);

        $this->assertFalse($tsp->isClosed());
        $tsp->close();
    }

    public function testConstructWithInvalidReportTypeThrows(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Report must be an instance of Report or NullReport');

        new TSProcessor(new \stdClass());
    }

    // =========================================================================
    // TSProcessor - Default property values
    // =========================================================================

    public function testDefaultPropertyValues(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();

        $this->assertFalse($tsp->ignoreJointTermination);
        $this->assertSame(16 * 1024 * 1024, $tsp->bufferSize);
        $this->assertSame(0, $tsp->maxFlushedPackets);
        $this->assertSame(0, $tsp->maxInputPackets);
        $this->assertSame(0, $tsp->maxOutputPackets);
        $this->assertSame(0, $tsp->initialInputPackets);
        $this->assertSame([0, 0], $tsp->addInputStuffing);
        $this->assertSame(0, $tsp->addStartStuffing);
        $this->assertSame(0, $tsp->addStopStuffing);
        $this->assertSame(0, $tsp->bitrate);
        $this->assertSame(5000, $tsp->bitrateAdjustInterval);
        $this->assertSame(0, $tsp->receiveTimeout);
        $this->assertFalse($tsp->logPluginIndex);

        $tsp->close();
    }

    // =========================================================================
    // TSProcessor - setPlugins() fluent API
    // =========================================================================

    public function testSetPluginsReturnsSelf(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();
        $result = $tsp->setPlugins('-I', 'file', 'input.ts', '-O', 'file', 'output.ts');

        $this->assertSame($tsp, $result, 'setPlugins() should return $this for fluent chaining.');

        $tsp->close();
    }

    // =========================================================================
    // TSProcessor - start() with invalid plugin throws TSPStartException
    // =========================================================================

    public function testStartWithNoPluginsThrowsTSPStartException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();

        $this->expectException(TSPStartException::class);

        $tsp->start();
    }

    public function testStartWithInvalidPluginThrowsTSPStartException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();
        $tsp->setPlugins('-I', 'nonexistent_plugin_that_does_not_exist');

        $this->expectException(TSPStartException::class);

        $tsp->start();
    }

    public function testStartWithMissingOutputThrowsTSPStartException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();
        $tsp->setPlugins('-I', 'inject');

        $this->expectException(TSPStartException::class);

        $tsp->start();
    }

    // =========================================================================
    // TSProcessor - close() / lifecycle
    // =========================================================================

    public function testCloseIsIdempotent(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();
        $tsp->close();
        $tsp->close(); // Should not throw.

        $this->assertTrue($tsp->isClosed());
    }

    public function testMethodAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();
        $tsp->close();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Cannot operate on a closed');

        $tsp->start();
    }

    public function testAbortAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();
        $tsp->close();

        $this->expectException(TsduckException::class);

        $tsp->abort();
    }

    public function testWaitForTerminationAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();
        $tsp->close();

        $this->expectException(TsduckException::class);

        $tsp->waitForTermination();
    }

    // =========================================================================
    // TSProcessor - setPlugins() builds buffer correctly
    // =========================================================================

    public function testSetPluginsBuildsBuffer(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();
        $tsp->setPlugins('-I', 'file', 'input.ts', '-O', 'file', 'output.ts');

        // Access the private pluginsBuffer via reflection to verify it was built.
        $ref = new \ReflectionProperty($tsp, 'pluginsBuffer');
        $ref->setAccessible(true);
        $buffer = $ref->getValue($tsp);

        $this->assertNotNull($buffer, 'pluginsBuffer should be set after setPlugins().');
        $this->assertGreaterThan(0, $buffer->getSize(), 'pluginsBuffer should not be empty.');

        $tsp->close();
    }

    // =========================================================================
    // InputSwitcher - Constructor
    // =========================================================================

    public function testInputSwitcherConstructWithDefaultNullReport(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();

        $this->assertFalse($sw->isClosed());
        $this->assertInstanceOf(PluginEventHandlerRegistry::class, $sw);
        $sw->close();
    }

    public function testInputSwitcherConstructWithExplicitNullReport(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher(NullReport::getInstance());

        $this->assertFalse($sw->isClosed());
        $sw->close();
    }

    public function testInputSwitcherConstructWithInvalidReportTypeThrows(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $this->expectException(\InvalidArgumentException::class);

        new InputSwitcher(new \stdClass());
    }

    // =========================================================================
    // InputSwitcher - Default property values
    // =========================================================================

    public function testInputSwitcherDefaultPropertyValues(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();

        $this->assertFalse($sw->fastSwitch);
        $this->assertFalse($sw->delayedSwitch);
        $this->assertFalse($sw->terminate);
        $this->assertFalse($sw->reusePort);
        $this->assertSame(0, $sw->firstInput);
        $this->assertSame(-1, $sw->primaryInput);
        $this->assertSame(1, $sw->cycleCount);
        $this->assertSame(0, $sw->bufferedPackets);
        $this->assertSame(0, $sw->maxInputPackets);
        $this->assertSame(0, $sw->maxOutputPackets);
        $this->assertSame(0, $sw->sockBuffer);
        $this->assertSame(0, $sw->remoteServerPort);
        $this->assertSame(0, $sw->receiveTimeout);
        $this->assertSame(0, $sw->eventUdpPort);
        $this->assertSame(0, $sw->eventTtl);

        $sw->close();
    }

    // =========================================================================
    // InputSwitcher - Fluent setters
    // =========================================================================

    public function testInputSwitcherSetPluginsReturnsSelf(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $result = $sw->setPlugins('-I', 'file', 'a.ts', '-I', 'file', 'b.ts', '-O', 'file', 'out.ts');

        $this->assertSame($sw, $result, 'setPlugins() should return $this.');
        $sw->close();
    }

    public function testSetEventCommandReturnsSelf(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $result = $sw->setEventCommand('/bin/echo', 'switched');

        $this->assertSame($sw, $result, 'setEventCommand() should return $this.');
        $sw->close();
    }

    public function testSetEventUdpAddressReturnsSelf(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $result = $sw->setEventUdpAddress('127.0.0.1');

        $this->assertSame($sw, $result, 'setEventUdpAddress() should return $this.');
        $sw->close();
    }

    public function testSetLocalAddressReturnsSelf(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $result = $sw->setLocalAddress('127.0.0.1');

        $this->assertSame($sw, $result, 'setLocalAddress() should return $this.');
        $sw->close();
    }

    // =========================================================================
    // InputSwitcher - start() with invalid config throws SwitchStartException
    // =========================================================================

    public function testStartWithNoPluginsThrowsSwitchStartException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();

        $this->expectException(SwitchStartException::class);

        $sw->start();
    }

    public function testStartWithSingleInputNoOutputThrowsSwitchStartException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $sw->setPlugins('-I', 'file', 'a.ts');

        $this->expectException(SwitchStartException::class);

        $sw->start();
    }

    // =========================================================================
    // InputSwitcher - close() / lifecycle
    // =========================================================================

    public function testInputSwitcherCloseIsIdempotent(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $sw->close();
        $sw->close();

        $this->assertTrue($sw->isClosed());
    }

    public function testInputSwitcherMethodAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $sw->close();

        $this->expectException(TsduckException::class);

        $sw->start();
    }

    public function testSetInputAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $sw->close();

        $this->expectException(TsduckException::class);

        $sw->setInput(0);
    }

    public function testNextInputAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $sw->close();

        $this->expectException(TsduckException::class);

        $sw->nextInput();
    }

    public function testPreviousInputAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $sw->close();

        $this->expectException(TsduckException::class);

        $sw->previousInput();
    }

    public function testCurrentInputAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $sw->close();

        $this->expectException(TsduckException::class);

        $sw->currentInput();
    }

    public function testStopAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $sw->close();

        $this->expectException(TsduckException::class);

        $sw->stop();
    }

    public function testInputSwitcherWaitForTerminationAfterCloseThrowsException(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();
        $sw->close();

        $this->expectException(TsduckException::class);

        $sw->waitForTermination();
    }

    // =========================================================================
    // InputSwitcher - InputSwitcher extends PluginEventHandlerRegistry
    // =========================================================================

    public function testInputSwitcherIsPluginEventHandlerRegistry(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $sw = new InputSwitcher();

        $this->assertInstanceOf(PluginEventHandlerRegistry::class, $sw);
        $sw->close();
    }

    public function testTSProcessorIsPluginEventHandlerRegistry(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $tsp = new TSProcessor();

        $this->assertInstanceOf(PluginEventHandlerRegistry::class, $tsp);
        $tsp->close();
    }

    // =========================================================================
    // StructMapper - set()
    // =========================================================================

    public function testStructMapperSetInt(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $ffi = LibTSDuck::getInstance();
        $args = $ffi->new('struct tspyTSProcessorArgs', false, false);

        StructMapper::set($args, 'buffer_size', 1024);

        $this->assertSame(1024, (int) $args->buffer_size);
    }

    public function testStructMapperSetBool(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $ffi = LibTSDuck::getInstance();
        $args = $ffi->new('struct tspyTSProcessorArgs', false, false);

        StructMapper::set($args, 'ignore_joint_termination', true);

        $this->assertSame(1, (int) $args->ignore_joint_termination);
    }

    public function testStructMapperSetBoolFalse(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $ffi = LibTSDuck::getInstance();
        $args = $ffi->new('struct tspyTSProcessorArgs', false, false);

        StructMapper::set($args, 'log_plugin_index', false);

        $this->assertSame(0, (int) $args->log_plugin_index);
    }

    public function testStructMapperSetZero(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $ffi = LibTSDuck::getInstance();
        $args = $ffi->new('struct tspyTSProcessorArgs', false, false);

        StructMapper::set($args, 'bitrate', 0);

        $this->assertSame(0, (int) $args->bitrate);
    }

    public function testStructMapperSetNegative(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $ffi = LibTSDuck::getInstance();
        $args = $ffi->new('struct tspyInputSwitcherArgs', false, false);

        StructMapper::set($args, 'primary_input', -1);

        $this->assertSame(-1, (int) $args->primary_input);
    }

    // =========================================================================
    // StructMapper - setString()
    // =========================================================================

    public function testStructMapperSetString(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $ffi = LibTSDuck::getInstance();
        $args = $ffi->new('struct tspyTSProcessorArgs', false, false);

        $buf = new InBuffer($ffi);
        $buf->append('-I');
        $buf->append('file');
        $buf->append('test.ts');

        StructMapper::setString($args, 'plugins', 'plugins_size', $buf);

        $this->assertGreaterThan(0, (int) $args->plugins_size);
        $this->assertNotNull($args->plugins);
    }

    public function testStructMapperSetStringEmpty(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $ffi = LibTSDuck::getInstance();
        $args = $ffi->new('struct tspyTSProcessorArgs', false, false);

        $buf = new InBuffer($ffi);

        StructMapper::setString($args, 'plugins', 'plugins_size', $buf);

        $this->assertSame(0, (int) $args->plugins_size);
    }

    // =========================================================================
    // StructMapper - full TSProcessorArgs struct mapping
    // =========================================================================

    public function testStructMapperMapsAllTSProcessorArgsFields(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $ffi = LibTSDuck::getInstance();
        $args = $ffi->new('struct tspyTSProcessorArgs', false, false);

        StructMapper::set($args, 'ignore_joint_termination', 1);
        StructMapper::set($args, 'buffer_size', 8 * 1024 * 1024);
        StructMapper::set($args, 'max_flushed_packets', 100);
        StructMapper::set($args, 'max_input_packets', 200);
        StructMapper::set($args, 'max_output_packets', 300);
        StructMapper::set($args, 'initial_input_packets', 50);
        StructMapper::set($args, 'add_input_stuffing_0', 10);
        StructMapper::set($args, 'add_input_stuffing_1', 100);
        StructMapper::set($args, 'add_start_stuffing', 5);
        StructMapper::set($args, 'add_stop_stuffing', 3);
        StructMapper::set($args, 'bitrate', 10000000);
        StructMapper::set($args, 'bitrate_adjust_interval', 2000);
        StructMapper::set($args, 'receive_timeout', 5000);
        StructMapper::set($args, 'log_plugin_index', 1);

        $this->assertSame(1, (int) $args->ignore_joint_termination);
        $this->assertSame(8 * 1024 * 1024, (int) $args->buffer_size);
        $this->assertSame(100, (int) $args->max_flushed_packets);
        $this->assertSame(200, (int) $args->max_input_packets);
        $this->assertSame(300, (int) $args->max_output_packets);
        $this->assertSame(50, (int) $args->initial_input_packets);
        $this->assertSame(10, (int) $args->add_input_stuffing_0);
        $this->assertSame(100, (int) $args->add_input_stuffing_1);
        $this->assertSame(5, (int) $args->add_start_stuffing);
        $this->assertSame(3, (int) $args->add_stop_stuffing);
        $this->assertSame(10000000, (int) $args->bitrate);
        $this->assertSame(2000, (int) $args->bitrate_adjust_interval);
        $this->assertSame(5000, (int) $args->receive_timeout);
        $this->assertSame(1, (int) $args->log_plugin_index);
    }

    // =========================================================================
    // StructMapper - full InputSwitcherArgs struct mapping
    // =========================================================================

    public function testStructMapperMapsAllInputSwitcherArgsFields(): void
    {
        if (!self::$nativeAvailable) {
            $this->markTestSkipped('TSDuck native library is not available.');
        }

        $ffi = LibTSDuck::getInstance();
        $args = $ffi->new('struct tspyInputSwitcherArgs', false, false);

        StructMapper::set($args, 'fast_switch', 1);
        StructMapper::set($args, 'delayed_switch', 1);
        StructMapper::set($args, 'terminate', 0);
        StructMapper::set($args, 'reuse_port', 0);
        StructMapper::set($args, 'first_input', 2);
        StructMapper::set($args, 'primary_input', 0);
        StructMapper::set($args, 'cycle_count', 5);
        StructMapper::set($args, 'buffered_packets', 1000);
        StructMapper::set($args, 'max_input_packets', 100);
        StructMapper::set($args, 'max_output_packets', 200);
        StructMapper::set($args, 'sock_buffer', 65536);
        StructMapper::set($args, 'remote_server_port', 9000);
        StructMapper::set($args, 'receive_timeout', 3000);
        StructMapper::set($args, 'event_udp_port', 9001);
        StructMapper::set($args, 'event_ttl', 64);

        $this->assertSame(1, (int) $args->fast_switch);
        $this->assertSame(1, (int) $args->delayed_switch);
        $this->assertSame(0, (int) $args->terminate);
        $this->assertSame(0, (int) $args->reuse_port);
        $this->assertSame(2, (int) $args->first_input);
        $this->assertSame(0, (int) $args->primary_input);
        $this->assertSame(5, (int) $args->cycle_count);
        $this->assertSame(1000, (int) $args->buffered_packets);
        $this->assertSame(100, (int) $args->max_input_packets);
        $this->assertSame(200, (int) $args->max_output_packets);
        $this->assertSame(65536, (int) $args->sock_buffer);
        $this->assertSame(9000, (int) $args->remote_server_port);
        $this->assertSame(3000, (int) $args->receive_timeout);
        $this->assertSame(9001, (int) $args->event_udp_port);
        $this->assertSame(64, (int) $args->event_ttl);
    }

    // =========================================================================
    // Exception hierarchy
    // =========================================================================

    public function testTSPStartExceptionExtendsTsduckException(): void
    {
        $exception = new TSPStartException('test');

        $this->assertInstanceOf(\Tsduck\Exception\TsduckException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testSwitchStartExceptionExtendsTsduckException(): void
    {
        $exception = new SwitchStartException('test');

        $this->assertInstanceOf(\Tsduck\Exception\TsduckException::class, $exception);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
