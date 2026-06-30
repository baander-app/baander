<?php

declare(strict_types=1);

namespace Tsduck\Util;

use FFI;
use Tsduck\Exception\TsduckException;

/**
 * Abstract base class for TSDuck objects backed by opaque C pointers.
 *
 * Provides lifecycle management for native objects allocated through the
 * tspy* C API. Subclasses store an opaque pointer returned by a native
 * constructor function and implement doClose() to call the corresponding
 * native destructor (e.g., tspyDeleteDuckContext, tspyDeleteSectionFile).
 *
 * Usage pattern:
 *   $obj = new SomeTSDuckClass($ffi, ...);
 *   $obj->someOperation();
 *   $obj->close();       // explicit cleanup (recommended)
 *   // or rely on __destruct() at GC time (safety net)
 *
 * Calling close() twice is a no-op. Calling any other method after close()
 * throws TsduckException.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
abstract class NativeObject
{
    /**
     * The FFI instance used to access native functions.
     */
    protected readonly FFI $ffi;

    /**
     * The opaque pointer to the native C++ object.
     *
     * Null after close() has been called.
     */
    private ?FFI\CData $pointer = null;

    /**
     * Whether close() has been called.
     */
    private bool $closed = false;

    /**
     * @param FFI          $ffi     The FFI instance bound to libtsduck
     * @param FFI\CData    $pointer The opaque pointer returned by a tspyNew* function
     */
    public function __construct(FFI $ffi, FFI\CData $pointer)
    {
        $this->ffi = $ffi;
        $this->pointer = $pointer;
    }

    /**
     * Explicitly frees the underlying native object.
     *
     * Calls doClose() (implemented by subclasses to invoke the native destructor),
     * then nulls the pointer. Idempotent -- calling close() twice is a no-op.
     * After this call, the object becomes unusable: any subsequent method call
     * will throw TsduckException.
     */
    final public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->doClose();
        $this->pointer = null;
    }

    /**
     * Safety net: calls close() during garbage collection.
     *
     * Users should prefer explicit close() calls for deterministic resource
     * cleanup. This destructor exists only as a fallback to prevent leaks
     * when objects go out of scope without an explicit close().
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Checks whether the native object has been closed.
     *
     * @return bool True if close() has been called
     */
    final public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Asserts that the native object is still open.
     *
     * Call this at the start of every public method that accesses the native
     * object. Throws TsduckException with a descriptive message if the object
     * has been closed.
     *
     * @throws TsduckException If the object has been closed
     */
    final protected function assertNotClosed(): void
    {
        if ($this->closed) {
            throw new TsduckException(sprintf(
                'Cannot operate on a closed %s. '
                . 'Check isClosed() before calling methods, '
                . 'or ensure close() is only called after all operations are complete.',
                static::class,
            ));
        }
    }

    /**
     * Returns the raw opaque pointer to the native C++ object.
     *
     * Returns null after close() has been called. Subclasses use this to
     * pass the pointer to tspy* C API functions.
     *
     * @return FFI\CData|null The native pointer, or null if closed
     */
    final protected function getPointer(): ?FFI\CData
    {
        return $this->pointer;
    }

    /**
     * Returns the raw opaque pointer for passing to other TSDuck classes.
     *
     * Unlike the protected getPointer(), this method is public so that
     * other TSDuck objects (e.g., SectionFile needs a DuckContext pointer,
     * PluginEventHandlerRegistry needs a handler pointer) can obtain the
     * native pointer for cross-object C API calls.
     *
     * @return FFI\CData The native pointer
     *
     * @throws TsduckException If the object has been closed
     */
    final public function nativePointer(): FFI\CData
    {
        $this->assertNotClosed();

        return $this->pointer;
    }

    /**
     * Performs the actual native resource cleanup.
     *
     * Subclasses must implement this to call the appropriate native destructor
     * (e.g., tspyDeleteDuckContext, tspyDeleteSectionFile). This method is
     * called exactly once by close(), before the pointer is nulled.
     *
     * When this method is called, getPointer() still returns the valid pointer.
     * After this method returns, the pointer will be set to null.
     */
    abstract protected function doClose(): void;
}
