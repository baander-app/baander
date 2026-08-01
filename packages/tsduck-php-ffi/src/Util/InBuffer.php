<?php

declare(strict_types=1);

namespace Tsduck\Util;

use FFI;

/**
 * UTF-16 LE input buffer for passing PHP strings to the TSDuck C API.
 *
 * The tspy* C API uses UTF-16 LE encoding for all string parameters,
 * passed as (const uint8_t* buffer, size_t size) pairs. This class
 * converts PHP UTF-8 strings to UTF-16 LE byte buffers.
 *
 * Multi-string buffers are supported: each call to append() after the
 * first adds a \xFF\xFF separator between strings (matching the Python
 * bindings' _InByteBuffer behavior).
 *
 * Usage:
 *   $buf = new InBuffer($ffi);
 *   $buf->append('hello');
 *   $buf->append('world');
 *   // Pass $buf->getBuffer() and $buf->getSize() to a tspy* function
 *
 * @see OutBuffer For the reverse direction (C buffer to PHP string)
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class InBuffer
{
    /**
     * The UTF-16 LE encoded byte data.
     *
     * @var list<int>
     */
    private array $data = [];

    /**
     * The FFI instance for allocating the C buffer pointer.
     */
    private readonly FFI $ffi;

    /**
     * Cached FFI\CData pointer to the buffer, or null if not yet allocated
     * or if the buffer has been modified since last allocation.
     */
    private ?FFI\CData $cdata = null;

    /**
     * @param FFI $ffi The FFI instance bound to libtsduck
     */
    public function __construct(FFI $ffi)
    {
        $this->ffi = $ffi;
    }

    /**
     * Clears all appended data, resetting the buffer to empty.
     */
    public function clear(): void
    {
        $this->data = [];
        $this->cdata = null;
    }

    /**
     * Appends a PHP UTF-8 string to the buffer as UTF-16 LE.
     *
     * When appending multiple strings, a \xFF\xFF separator is inserted
     * between them (after the first string). This matches the TSDuck
     * convention for multi-string buffers (e.g., plugin lists).
     *
     * @param string $text The PHP string to append (UTF-8 encoded)
     */
    public function append(string $text): void
    {
        if (count($this->data) > 0) {
            $this->data[] = 0xFF;
            $this->data[] = 0xFF;
        }

        $encoded = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        foreach (str_split($encoded) as $byte) {
            $this->data[] = ord($byte);
        }

        // Invalidate cached C pointer since data changed.
        $this->cdata = null;
    }

    /**
     * Returns a uint8_t* pointer to the buffer for passing to C functions.
     *
     * The returned pointer is valid until clear() or append() is called.
     * If the buffer is empty, returns a pointer to a single null byte.
     *
     * @return FFI\CData The uint8_t* pointer
     */
    public function getBuffer(): FFI\CData
    {
        $size = count($this->data);

        if ($size === 0) {
            // Return a pointer to an empty buffer (single null byte).
            $this->cdata = $this->ffi->new('uint8_t[1]', false, false);
            $this->cdata[0] = 0;

            return $this->cdata;
        }

        if ($this->cdata === null) {
            $this->cdata = $this->ffi->new("uint8_t[{$size}]", false, false);
            for ($i = 0; $i < $size; $i++) {
                $this->cdata[$i] = $this->data[$i];
            }
        }

        return $this->cdata;
    }

    /**
     * Returns the buffer size in bytes.
     *
     * @return int The size of the buffer in bytes
     */
    public function getSize(): int
    {
        return count($this->data);
    }
}
