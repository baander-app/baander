<?php

namespace Tests\Unit\Primitives;

use App\Primitives\Bytes;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BytesTest extends TestCase
{
    // ── format() ────────────────────────────────────────────────────

    #[Test]
    public function it_formats_zero_bytes(): void
    {
        $this->assertSame('0 B', Bytes::format(0));
    }

    #[Test]
    public function it_formats_bytes_less_than_one_kilobyte(): void
    {
        $this->assertSame('512 B', Bytes::format(512));
    }

    #[Test]
    public function it_formats_exactly_one_kilobyte(): void
    {
        $this->assertSame('1.00 KB', Bytes::format(1024));
    }

    #[Test]
    public function it_formats_kilobytes_with_default_precision(): void
    {
        $this->assertSame('1.50 KB', Bytes::format(1536));
    }

    #[Test]
    public function it_formats_kilobytes_with_zero_precision(): void
    {
        $this->assertSame('2 KB', Bytes::format(1536, precision: 0));
    }

    #[Test]
    public function it_formats_megabytes(): void
    {
        $this->assertSame('1.00 MB', Bytes::format(1048576));
        $this->assertSame('1.50 MB', Bytes::format(1572864));
    }

    #[Test]
    public function it_formats_gigabytes(): void
    {
        $this->assertSame('1.00 GB', Bytes::format(1073741824));
    }

    #[Test]
    public function it_formats_terabytes(): void
    {
        $this->assertSame('1.00 TB', Bytes::format(1099511627776));
    }

    #[Test]
    public function it_handles_negative_bytes_by_converting_to_absolute(): void
    {
        $this->assertSame('1.00 KB', Bytes::format(-1024));
    }

    #[Test]
    public function it_accepts_float_byte_values(): void
    {
        $this->assertSame('1.00 KB', Bytes::format(1024.0));
    }

    #[Test]
    public function it_uses_custom_separator(): void
    {
        $this->assertSame('1.50KB', Bytes::format(1536, separator: ''));
    }

    // ── formatBinary() ─────────────────────────────────────────────

    #[Test]
    public function it_formats_bytes_as_binary(): void
    {
        $this->assertSame('1.00 KiB', Bytes::formatBinary(1024));
        $this->assertSame('1.50 KiB', Bytes::formatBinary(1536));
    }

    #[Test]
    public function it_formats_binary_megabytes(): void
    {
        $this->assertSame('1.00 MiB', Bytes::formatBinary(1048576));
    }

    #[Test]
    public function it_formats_binary_gigabytes(): void
    {
        $this->assertSame('1.00 GiB', Bytes::formatBinary(1073741824));
    }

    #[Test]
    public function it_formats_binary_bytes_below_kilobyte(): void
    {
        $this->assertSame('512 B', Bytes::formatBinary(512));
    }

    // ── parse() ─────────────────────────────────────────────────────

    #[Test]
    public function it_parses_bytes(): void
    {
        $this->assertEquals(512, Bytes::parse('512 B'));
    }

    #[Test]
    public function it_parses_kilobytes(): void
    {
        $this->assertEquals(1536, Bytes::parse('1.5 KB'));
    }

    #[Test]
    public function it_parses_megabytes(): void
    {
        $this->assertEquals(10485760, Bytes::parse('10 MB'));
    }

    #[Test]
    public function it_parses_gigabytes(): void
    {
        $this->assertEquals(1073741824, Bytes::parse('1 GB'));
    }

    #[Test]
    public function it_parses_binary_units(): void
    {
        $this->assertEquals(1024, Bytes::parse('1 KiB'));
        $this->assertEquals(1048576, Bytes::parse('1 MiB'));
    }

    #[Test]
    public function it_parses_lowercase_input(): void
    {
        $this->assertEquals(1024, Bytes::parse('1 kb'));
    }

    #[Test]
    public function it_returns_zero_for_unparseable_string(): void
    {
        $this->assertSame(0, Bytes::parse('not a number'));
    }

    #[Test]
    public function it_trims_whitespace_before_parsing(): void
    {
        $this->assertEquals(1024, Bytes::parse('  1 KB  '));
    }

    // ── getUnit() ───────────────────────────────────────────────────

    #[Test]
    public function it_returns_bytes_unit_for_small_values(): void
    {
        $this->assertSame('B', Bytes::getUnit(512));
    }

    #[Test]
    public function it_returns_kilobytes_unit(): void
    {
        $this->assertSame('KB', Bytes::getUnit(1024));
    }

    #[Test]
    public function it_returns_megabytes_unit(): void
    {
        $this->assertSame('MB', Bytes::getUnit(1048576));
    }

    #[Test]
    public function it_returns_gigabytes_unit(): void
    {
        $this->assertSame('GB', Bytes::getUnit(1073741824));
    }

    #[Test]
    public function it_returns_terabytes_unit(): void
    {
        $this->assertSame('TB', Bytes::getUnit(1099511627776));
    }

    // ── toUnit() ────────────────────────────────────────────────────

    #[Test]
    public function it_converts_bytes_to_kilobytes(): void
    {
        $this->assertSame(1.0, Bytes::toUnit(1024, 'KB'));
    }

    #[Test]
    public function it_converts_bytes_to_megabytes(): void
    {
        $this->assertSame(1.0, Bytes::toUnit(1048576, 'MB'));
    }

    #[Test]
    public function it_converts_with_precision(): void
    {
        $this->assertSame(1.5, Bytes::toUnit(1536, 'KB', precision: 1));
    }

    #[Test]
    public function it_handles_binary_units_in_to_unit(): void
    {
        $this->assertSame(1.0, Bytes::toUnit(1024, 'KiB'));
    }

    #[Test]
    public function it_throws_exception_for_invalid_unit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid unit: FOO');

        Bytes::toUnit(1024, 'FOO');
    }

    // ── convert() ───────────────────────────────────────────────────

    #[Test]
    public function it_converts_kilobytes_to_megabytes(): void
    {
        $this->assertSame(1.0, Bytes::convert(1024, 'KB', 'MB'));
    }

    #[Test]
    public function it_converts_megabytes_to_gigabytes(): void
    {
        $this->assertSame(1.0, Bytes::convert(1024, 'MB', 'GB'));
    }

    #[Test]
    public function it_converts_gigabytes_to_megabytes(): void
    {
        $this->assertSame(1024.0, Bytes::convert(1, 'GB', 'MB'));
    }

    #[Test]
    public function it_converts_with_custom_precision(): void
    {
        $this->assertSame(0.98, Bytes::convert(1000, 'KB', 'MB', precision: 2));
    }

    #[Test]
    public function it_throws_exception_for_invalid_units_in_convert(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid unit provided');

        Bytes::convert(1, 'FOO', 'BAR');
    }

    // ── formatAuto() ────────────────────────────────────────────────

    #[Test]
    public function it_auto_formats_bytes(): void
    {
        $this->assertSame('512 B', Bytes::formatAuto(512));
    }

    #[Test]
    public function it_auto_formats_kilobytes_with_zero_precision(): void
    {
        $this->assertSame('500 KB', Bytes::formatAuto(500 * 1024));
    }

    #[Test]
    public function it_auto_formats_megabytes_with_one_precision(): void
    {
        $this->assertSame('1.5 MB', Bytes::formatAuto(1.5 * 1024 * 1024));
    }

    #[Test]
    public function it_auto_formats_gigabytes_with_two_precision(): void
    {
        $this->assertSame('1.00 GB', Bytes::formatAuto(1.0 * 1024 * 1024 * 1024));
    }
}
