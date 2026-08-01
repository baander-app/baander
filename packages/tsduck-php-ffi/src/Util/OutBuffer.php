<?php

declare(strict_types=1);

namespace Tsduck\Util;

use FFI;

/**
 * Output buffer for receiving UTF-16 LE data from the TSDuck C API.
 *
 * The tspy* C API writes data into buffers provided by the caller using
 * (uint8_t* buffer, size_t* size) parameter pairs. On input, size contains
 * the buffer capacity; on output, it contains the actual data size written.
 *
 * This class handles the "double-buffer" pattern used by functions like
 * tspySectionFileToXML and tspySectionFileToJSON:
 *   1. Call the C function with an initial buffer size.
 *   2. If the buffer was too small, the C function reports the required size.
 *   3. Resize the buffer and retry.
 *
 * Usage:
 *   $buf = new OutBuffer($ffi);
 *   $ffi->tspySectionFileToXML($sf, $buf->getBuffer(), $buf->getSizePtr());
 *   $xml = $buf->toString();
 *
 * @see InBuffer For the reverse direction (PHP string to C buffer)
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class OutBuffer
{
    /**
     * The FFI instance for allocating buffers.
     */
    private readonly FFI $ffi;

    /**
     * The allocated C buffer (uint8_t array).
     */
    private FFI\CData $buffer;

    /**
     * The FFI size_t variable tracking the current buffer capacity.
     * Updated by C functions to report actual data written.
     */
    private FFI\CData $size;

    /**
     * @param FFI $ffi         The FFI instance bound to libtsduck
     * @param int $initialSize The initial buffer capacity in bytes (default: 4096)
     */
    public function __construct(FFI $ffi, int $initialSize = 4096)
    {
        $this->ffi = $ffi;
        $this->size = $ffi->new('size_t', false, false);
        $this->size->cdata = $initialSize;
        $this->buffer = $ffi->new("uint8_t[{$initialSize}]", false, false);
    }

    /**
     * Returns the uint8_t* pointer to the buffer for C functions to write into.
     *
     * @return FFI\CData The uint8_t* pointer
     */
    public function getBuffer(): FFI\CData
    {
        return $this->buffer;
    }

    /**
     * Returns the current buffer capacity in bytes.
     *
     * This value is set by the constructor or resize(), and may be
     * overwritten by C functions that write to getSizePtr().
     *
     * @return int The buffer size
     */
    public function getSize(): int
    {
        return (int) $this->size->cdata;
    }

    /**
     * Returns a pointer to the size_t variable for passing to C functions.
     *
     * C functions use this to both read the buffer capacity (on input)
     * and report the actual data size (on output).
     *
     * @return FFI\CData Pointer to the size_t variable
     */
    public function getSizePtr(): FFI\CData
    {
        return FFI::addr($this->size);
    }

    /**
     * Reallocates the buffer with a new capacity.
     *
     * Use this when a C function reports that the buffer was too small.
     * The size_t variable is also updated to reflect the new capacity.
     *
     * Note: This invalidates any previous pointer returned by getBuffer().
     * You must call getBuffer() again after resize() to obtain the new pointer.
     *
     * @param int $newSize The new buffer capacity in bytes
     */
    public function resize(int $newSize): void
    {
        $this->size->cdata = $newSize;
        $this->buffer = $this->ffi->new("uint8_t[{$newSize}]", false, false);
    }

    /**
     * Converts the buffer contents from UTF-16 LE to a PHP UTF-8 string.
     *
     * Reads up to getSize() bytes from the buffer (the value set by the
     * C function after writing data) and decodes from UTF-16 LE.
     *
     * @return string The decoded PHP string (UTF-8), or empty string if no data
     */
    public function toString(): string
    {
        $actualSize = $this->getSize();

        if ($actualSize === 0) {
            return '';
        }

        $bytes = '';
        for ($i = 0; $i < $actualSize; $i++) {
            $bytes .= chr($this->buffer[$i]);
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
    }
}
