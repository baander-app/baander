<?php

declare(strict_types=1);

namespace Tsduck;

use Tsduck\Util\NativeObject;

/**
 * Abstract base class for objects that can register plugin event handlers.
 *
 * PluginEventHandlerRegistry provides methods to register event handler
 * callbacks that are invoked during MPEG-TS processing. Event handlers
 * can be registered globally (by event code) or scoped to input/output
 * plugins.
 *
 * This class is the base for TSProcessor and InputSwitcher, which both
 * support plugin event handling.
 *
 * @see TSProcessor
 * @see InputSwitcher
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
abstract class PluginEventHandlerRegistry extends NativeObject
{
    /**
     * Registers an event handler for a specific event code.
     *
     * The handler will be invoked whenever the specified event occurs
     * during TS processing.
     *
     * @param NativeObject $handler    The event handler wrapper (must expose a native pointer)
     * @param int          $eventCode  The event code to handle (uint32_t)
     */
    public function registerEventHandler(NativeObject $handler, int $eventCode): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyPluginEventHandlerRegister(
            $this->nativePointer(),
            $handler->nativePointer(),
            $eventCode,
        );
    }

    /**
     * Registers an event handler for all events from the input plugin.
     *
     * The handler will be invoked for any event emitted by the input plugin
     * during TS processing.
     *
     * @param NativeObject $handler The event handler wrapper (must expose a native pointer)
     */
    public function registerInputEventHandler(NativeObject $handler): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyPluginEventHandlerRegisterInput(
            $this->nativePointer(),
            $handler->nativePointer(),
        );
    }

    /**
     * Registers an event handler for all events from the output plugin.
     *
     * The handler will be invoked for any event emitted by the output plugin
     * during TS processing.
     *
     * @param NativeObject $handler The event handler wrapper (must expose a native pointer)
     */
    public function registerOutputEventHandler(NativeObject $handler): void
    {
        $this->assertNotClosed();
        $this->ffi->tspyPluginEventHandlerRegisterOutput(
            $this->nativePointer(),
            $handler->nativePointer(),
        );
    }
}
