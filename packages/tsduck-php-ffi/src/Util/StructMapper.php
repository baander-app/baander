<?php

declare(strict_types=1);

namespace Tsduck\Util;

use FFI;

/**
 * Utility for mapping PHP values to FFI C struct fields.
 *
 * Provides type-aware assignment of PHP values to C struct fields,
 * handling conversions between PHP scalar types and C types such as
 * long, size_t, and const uint8_t* (via InBuffer for UTF-16 strings).
 *
 * Usage:
 *   $args = $ffi->new('struct tspyTSProcessorArgs');
 *   StructMapper::set($args, 'buffer_size', 1024);       // int -> long
 *   StructMapper::setString($args, 'plugins', $buf);      // InBuffer -> uint8_t* + size_t
 *
 * @see InBuffer For UTF-16 string buffer handling
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
final class StructMapper
{
    /**
     * Sets a C struct field from a PHP value with type-aware conversion.
     *
     * Handles the following mappings:
     *   - int/bool -> long (C ABI compatible)
     *   - FFI\CData (uint8_t*) -> assigned directly (for pre-built buffers)
     *   - int (for size_t fields) -> assigned directly
     *
     * @param FFI\CData $struct The C struct instance
     * @param string    $field  The field name within the struct
     * @param mixed     $value  The PHP value to assign
     */
    public static function set(FFI\CData $struct, string $field, mixed $value): void
    {
        if (is_bool($value)) {
            $struct->{$field} = (int) $value;
        } elseif (is_int($value)) {
            $struct->{$field} = $value;
        } elseif ($value instanceof FFI\CData) {
            $struct->{$field} = $value;
        } else {
            $struct->{$field} = $value;
        }
    }

    /**
     * Sets a string field on a C struct using an InBuffer.
     *
     * Assigns the UTF-16 LE buffer pointer to the pointer field and
     * the buffer size to the corresponding size_t field. This is the
     * standard pattern for all string parameters in the tspy* API.
     *
     * @param FFI\CData $struct       The C struct instance
     * @param string    $pointerField The field name for the const uint8_t* pointer
     * @param string    $sizeField    The field name for the size_t size
     * @param InBuffer  $buffer       The InBuffer containing the UTF-16 data
     */
    public static function setString(
        FFI\CData $struct,
        string $pointerField,
        string $sizeField,
        InBuffer $buffer,
    ): void {
        $struct->{$pointerField} = $buffer->getBuffer();
        $struct->{$sizeField} = $buffer->getSize();
    }
}
