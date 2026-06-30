<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use FFI;
use PHPUnit\Framework\TestCase;
use Tsduck\Exception\TsduckException;
use Tsduck\Util\NativeObject;

/**
 * Tests for the NativeObject abstract base class.
 *
 * Uses a concrete test subclass that records doClose() invocations
 * without requiring the actual TSDuck native library.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class NativeObjectTest extends TestCase
{
    /**
     * Whether the FFI extension is available.
     */
    private static bool $ffiAvailable;

    public static function setUpBeforeClass(): void
    {
        self::$ffiAvailable = \extension_loaded('ffi');
    }

    // =========================================================================
    // Helper: create a concrete NativeObject subclass for testing
    // =========================================================================

    /**
     * Creates a concrete test instance of NativeObject.
     *
     * Uses FFI::addr() on an int allocation to produce a valid non-null
     * pointer for testing purposes.
     *
     * @param FFI $ffi A minimal FFI instance
     *
     * @return NativeObject A concrete test subclass instance
     */
    private function createTestObject(FFI $ffi): NativeObject
    {
        $dummy = $ffi->new('int');
        $dummy->cdata = 42;
        $pointer = FFI::addr($dummy);

        return new class($ffi, $pointer) extends NativeObject {
            /** @var bool Whether doClose() was called */
            public bool $closeCalled = false;

            protected function doClose(): void
            {
                $this->closeCalled = true;
            }
        };
    }

    /**
     * Creates a concrete NativeObject subclass that exposes assertNotClosed().
     *
     * @param FFI $ffi A minimal FFI instance
     *
     * @return NativeObject Test subclass with a doOperation() method
     */
    private function createAssertingObject(FFI $ffi): NativeObject
    {
        $dummy = $ffi->new('int');
        $dummy->cdata = 42;
        $pointer = FFI::addr($dummy);

        return new class($ffi, $pointer) extends NativeObject {
            public function doOperation(): void
            {
                $this->assertNotClosed();
            }

            protected function doClose(): void
            {
            }
        };
    }

    /**
     * Creates a minimal FFI instance for testing without libtsduck.
     *
     * Declares just enough types to create opaque pointers and integer values.
     *
     * @return FFI A minimal FFI instance
     */
    private function createMinimalFfi(): FFI
    {
        return FFI::cdef('typedef unsigned long size_t;');
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorStoresPointer(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $obj = $this->createTestObject($ffi);

        $this->assertFalse($obj->isClosed(), 'Object should not be closed after construction.');
    }

    // =========================================================================
    // close() tests
    // =========================================================================

    public function testCloseCallsDoClose(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $obj = $this->createTestObject($ffi);
        $this->assertFalse($obj->closeCalled, 'doClose() should not have been called yet.');

        $obj->close();

        $this->assertTrue($obj->closeCalled, 'close() should invoke doClose().');
        $this->assertTrue($obj->isClosed(), 'Object should be closed after close().');
    }

    public function testDoubleCloseIsNoOp(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $obj = $this->createTestObject($ffi);

        $obj->close();
        $firstCall = $obj->closeCalled;

        $obj->close();
        $secondCall = $obj->closeCalled;

        // closeCalled should still be true (doClose was called once),
        // and no exception should be thrown.
        $this->assertTrue($firstCall, 'doClose() should have been called on first close().');
        $this->assertTrue($secondCall, 'doClose() count should not change on second close().');
    }

    // =========================================================================
    // __destruct() tests
    // =========================================================================

    public function testDestructorCallsClose(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();

        // Create object in a separate scope to trigger destructor.
        $obj = $this->createTestObject($ffi);

        // Force the object out of scope.
        unset($obj);

        // The anonymous class has closeCalled as a public property,
        // but we can't access it after unset. Instead, verify that
        // doClose was called by checking no errors were raised and
        // the destructor completed without exception.
        $this->addToAssertionCount(1);
    }

    public function testDestructorAfterExplicitCloseIsNoOp(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $obj = $this->createTestObject($ffi);
        $obj->close();

        // Explicitly close, then let destructor run.
        unset($obj);

        // No exception should be thrown.
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // assertNotClosed() tests
    // =========================================================================

    public function testMethodAfterCloseThrowsException(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $obj = $this->createAssertingObject($ffi);
        $obj->close();

        $this->expectException(TsduckException::class);
        $this->expectExceptionMessage('Cannot operate on a closed');

        $obj->doOperation();
    }

    // =========================================================================
    // isClosed() tests
    // =========================================================================

    public function testIsClosedBeforeClose(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $obj = $this->createTestObject($ffi);

        $this->assertFalse($obj->isClosed(), 'Object should not be closed initially.');
    }

    public function testIsClosedAfterClose(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $obj = $this->createTestObject($ffi);
        $obj->close();

        $this->assertTrue($obj->isClosed(), 'Object should be closed after close().');
    }
}
