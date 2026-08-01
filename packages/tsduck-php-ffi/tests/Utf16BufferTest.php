<?php

declare(strict_types=1);

namespace Tsduck\Tests;

use FFI;
use PHPUnit\Framework\TestCase;
use Tsduck\Util\InBuffer;
use Tsduck\Util\OutBuffer;

/**
 * Tests for InBuffer and OutBuffer UTF-16 string marshaling utilities.
 *
 * These tests exercise the UTF-8 <-> UTF-16 LE conversion logic
 * without requiring the TSDuck native library. Only the FFI extension
 * is needed for buffer allocation.
 *
 * @psalm-suppress UndefinedClass (FFI extension classes are not known to Psalm)
 */
class Utf16BufferTest extends TestCase
{
    /**
     * Whether the FFI extension is available.
     */
    private static bool $ffiAvailable;

    public static function setUpBeforeClass(): void
    {
        self::$ffiAvailable = \extension_loaded('ffi');
    }

    /**
     * Creates a minimal FFI instance for testing without libtsduck.
     *
     * @return FFI A minimal FFI instance
     */
    private function createMinimalFfi(): FFI
    {
        return FFI::cdef('typedef unsigned long size_t; typedef unsigned char uint8_t;');
    }

    // =========================================================================
    // InBuffer tests
    // =========================================================================

    public function testInBufferEmptyInitially(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new InBuffer($ffi);

        $this->assertSame(0, $buf->getSize(), 'Empty buffer should have size 0.');
    }

    public function testInBufferAppendSingleString(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new InBuffer($ffi);
        $buf->append('hello');

        // "hello" in UTF-16LE is 10 bytes (5 chars * 2 bytes each).
        $expectedSize = 5 * 2;
        $this->assertSame($expectedSize, $buf->getSize(), 'Single ASCII string should be 10 bytes in UTF-16LE.');

        // Verify the buffer content.
        $expected = mb_convert_encoding('hello', 'UTF-16LE', 'UTF-8');
        $bufferPtr = $buf->getBuffer();
        $actual = '';
        for ($i = 0; $i < $buf->getSize(); $i++) {
            $actual .= chr($bufferPtr[$i]);
        }
        $this->assertSame($expected, $actual, 'Buffer content should match UTF-16LE encoding.');
    }

    public function testInBufferAppendEmptyString(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new InBuffer($ffi);
        $buf->append('');

        // Empty string in UTF-16LE: just the BOM? No, mb_convert_encoding of
        // empty string produces empty string. So size should be 0.
        $this->assertSame(0, $buf->getSize(), 'Empty string should produce 0 bytes.');
    }

    public function testInBufferMultiStringSeparator(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new InBuffer($ffi);
        $buf->append('hello');
        $buf->append('world');

        // "hello" = 10 bytes UTF-16LE + separator \xFF\xFF (2 bytes) + "world" = 10 bytes
        $expectedSize = 10 + 2 + 10;
        $this->assertSame($expectedSize, $buf->getSize(), 'Two strings should have separator between them.');

        // Verify the separator is at byte offset 10-11.
        $bufferPtr = $buf->getBuffer();
        $this->assertSame(0xFF, $bufferPtr[10], 'Separator first byte should be 0xFF.');
        $this->assertSame(0xFF, $bufferPtr[11], 'Separator second byte should be 0xFF.');

        // Verify "world" starts at offset 12.
        $worldBytes = mb_convert_encoding('world', 'UTF-16LE', 'UTF-8');
        for ($i = 0; $i < strlen($worldBytes); $i++) {
            $this->assertSame(
                ord($worldBytes[$i]),
                $bufferPtr[12 + $i],
                "Second string byte at offset 12+{$i} should match.",
            );
        }
    }

    public function testInBufferThreeStringsSeparator(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new InBuffer($ffi);
        $buf->append('a');
        $buf->append('b');
        $buf->append('c');

        // "a" = 2 bytes + separator 2 + "b" = 2 + separator 2 + "c" = 2 = 10 bytes
        $expectedSize = 2 + 2 + 2 + 2 + 2;
        $this->assertSame($expectedSize, $buf->getSize(), 'Three strings should have two separators.');

        // Verify both separators.
        $bufferPtr = $buf->getBuffer();
        $this->assertSame(0xFF, $bufferPtr[2], 'First separator at offset 2.');
        $this->assertSame(0xFF, $bufferPtr[3], 'First separator at offset 3.');
        $this->assertSame(0xFF, $bufferPtr[6], 'Second separator at offset 6.');
        $this->assertSame(0xFF, $bufferPtr[7], 'Second separator at offset 7.');
    }

    public function testInBufferClearResetsState(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new InBuffer($ffi);
        $buf->append('hello');
        $buf->append('world');

        $this->assertSame(22, $buf->getSize());

        $buf->clear();

        $this->assertSame(0, $buf->getSize(), 'Buffer should be empty after clear().');
    }

    public function testInBufferClearThenAppend(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new InBuffer($ffi);
        $buf->append('hello');
        $buf->clear();
        $buf->append('new');

        // "new" in UTF-16LE = 6 bytes. No separator since this is the first after clear.
        $expectedSize = 3 * 2;
        $this->assertSame($expectedSize, $buf->getSize(), 'After clear, first append should have no separator.');
    }

    public function testInBufferGetBufferOnEmpty(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new InBuffer($ffi);

        // Should not throw, should return a valid pointer.
        $bufferPtr = $buf->getBuffer();
        $this->assertNotNull($bufferPtr);
    }

    public function testInBufferUnicodeString(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new InBuffer($ffi);

        // Euro sign is 3 bytes in UTF-8, 2 bytes in UTF-16.
        $buf->append("\u{20AC}"); // Euro sign

        $expectedSize = 2; // Euro sign is 2 bytes in UTF-16LE
        $this->assertSame($expectedSize, $buf->getSize(), 'Euro sign should be 2 bytes in UTF-16LE.');

        // Verify round-trip: decode back to UTF-8.
        $bufferPtr = $buf->getBuffer();
        $bytes = '';
        for ($i = 0; $i < $buf->getSize(); $i++) {
            $bytes .= chr($bufferPtr[$i]);
        }
        $decoded = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
        $this->assertSame("\u{20AC}", $decoded, 'Round-trip encoding should preserve the Euro sign.');
    }

    // =========================================================================
    // OutBuffer tests
    // =========================================================================

    public function testOutBufferDefaultSize(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new OutBuffer($ffi);

        $this->assertSame(4096, $buf->getSize(), 'Default OutBuffer size should be 4096.');
    }

    public function testOutBufferCustomInitialSize(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new OutBuffer($ffi, 1024);

        $this->assertSame(1024, $buf->getSize(), 'Custom initial size should be respected.');
    }

    public function testOutBufferResize(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new OutBuffer($ffi, 4096);
        $this->assertSame(4096, $buf->getSize());

        $buf->resize(8192);
        $this->assertSame(8192, $buf->getSize(), 'Size should update after resize().');

        $buf->resize(128);
        $this->assertSame(128, $buf->getSize(), 'Size should update after shrinking.');
    }

    public function testOutBufferGetBufferReturnsPointer(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new OutBuffer($ffi);

        $bufferPtr = $buf->getBuffer();
        $this->assertNotNull($bufferPtr, 'getBuffer() should return a non-null pointer.');
    }

    public function testOutBufferGetSizePtr(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new OutBuffer($ffi, 2048);

        $sizePtr = $buf->getSizePtr();
        $this->assertNotNull($sizePtr, 'getSizePtr() should return a non-null pointer.');
    }

    public function testOutBufferToStringEmpty(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();
        $buf = new OutBuffer($ffi, 4096);

        // Simulate a C function reporting that it wrote 0 bytes
        // by writing 0 to the size pointer (what a C function does on output).
        $sizePtr = $buf->getSizePtr();
        $sizePtr[0] = 0;

        $result = $buf->toString();
        $this->assertSame('', $result, 'OutBuffer with size 0 should return empty string.');
    }

    public function testOutBufferRoundTrip(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();

        // Create an OutBuffer and manually write UTF-16LE data into it.
        $text = 'hello world';
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $textBytes = strlen($utf16);

        $buf = new OutBuffer($ffi, $textBytes);

        // Simulate a C function writing data into the buffer.
        $bufferPtr = $buf->getBuffer();
        for ($i = 0; $i < $textBytes; $i++) {
            $bufferPtr[$i] = ord($utf16[$i]);
        }

        // Set the actual data size via resize (simulating what the C function
        // would do by writing to the size_t* parameter).
        // We need to resize to the data size so toString reads the right amount.
        $buf->resize($textBytes);

        // Re-write the data since resize allocated a new buffer.
        $bufferPtr = $buf->getBuffer();
        for ($i = 0; $i < $textBytes; $i++) {
            $bufferPtr[$i] = ord($utf16[$i]);
        }

        $result = $buf->toString();
        $this->assertSame($text, $result, 'OutBuffer round-trip should preserve the original string.');
    }

    public function testOutBufferUnicodeRoundTrip(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();

        $text = "\u{20AC}\u{00A9}TSDuck"; // Euro, Copyright, TSDuck
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $textBytes = strlen($utf16);

        $buf = new OutBuffer($ffi, $textBytes);
        $bufferPtr = $buf->getBuffer();
        for ($i = 0; $i < $textBytes; $i++) {
            $bufferPtr[$i] = ord($utf16[$i]);
        }

        // Resize and re-write to simulate C function output.
        $buf->resize($textBytes);
        $bufferPtr = $buf->getBuffer();
        for ($i = 0; $i < $textBytes; $i++) {
            $bufferPtr[$i] = ord($utf16[$i]);
        }

        $result = $buf->toString();
        $this->assertSame($text, $result, 'OutBuffer round-trip should preserve Unicode characters.');
    }

    public function testOutBufferResizeTooSmallThenRetry(): void
    {
        if (!self::$ffiAvailable) {
            $this->markTestSkipped('FFI extension is not available.');
        }

        $ffi = $this->createMinimalFfi();

        // Simulate the double-buffer pattern: first try with a small buffer,
        // then resize when we discover it's too small.
        $text = 'This is a longer string that needs a bigger buffer';
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $actualSize = strlen($utf16);

        // First try: buffer too small.
        $buf = new OutBuffer($ffi, 10);
        $this->assertSame(10, $buf->getSize());

        // "Discover" the buffer is too small (simulating C function returning required size).
        $requiredSize = $actualSize;
        $this->assertGreaterThan($buf->getSize(), $requiredSize, 'Required size should exceed initial buffer.');

        // Retry with correct size.
        $buf->resize($requiredSize);
        $bufferPtr = $buf->getBuffer();
        for ($i = 0; $i < $requiredSize; $i++) {
            $bufferPtr[$i] = ord($utf16[$i]);
        }

        $result = $buf->toString();
        $this->assertSame($text, $result, 'After resize, OutBuffer should contain the full string.');
    }
}
