<?php

namespace Tests\Unit\Primitives;

use App\Primitives\Number;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NumberTest extends TestCase
{
    // ─── Static: format ────────────────────────────────────────────────────────

    #[Test]
    public function format_formats_integer(): void
    {
        $this->assertSame('1,234', Number::format(1234));
        $this->assertSame('1,234,567', Number::format(1234567));
    }

    #[Test]
    public function format_formats_with_decimals(): void
    {
        $this->assertSame('1,234.56', Number::format(1234.56, 2));
        $this->assertSame('1,234.6', Number::format(1234.56, 1));
    }

    #[Test]
    public function format_uses_custom_separators(): void
    {
        $this->assertSame('1.234,56', Number::format(1234.56, 2, ',', '.'));
        $this->assertSame('1 234,56', Number::format(1234.56, 2, ',', ' '));
    }

    #[Test]
    public function format_handles_negative_numbers(): void
    {
        $this->assertSame('-1,234', Number::format(-1234));
        $this->assertSame('-1,234.56', Number::format(-1234.56, 2));
    }

    #[Test]
    public function format_handles_zero(): void
    {
        $this->assertSame('0', Number::format(0));
        $this->assertSame('0.00', Number::format(0, 2));
    }

    #[Test]
    public function format_handles_null_separators(): void
    {
        $this->assertSame('1,234.00', Number::format(1234, 2, null, null));
    }

    // ─── Static: currency ──────────────────────────────────────────────────────

    #[Test]
    public function currency_formats_usd(): void
    {
        $result = Number::currency(1234.50, 'USD');
        $this->assertStringContainsString('1234', $result);
        $this->assertStringContainsString('50', $result);
    }

    #[Test]
    public function currency_handles_zero(): void
    {
        $result = Number::currency(0, 'USD');
        $this->assertStringContainsString('0', $result);
    }

    #[Test]
    public function currency_handles_negative(): void
    {
        $result = Number::currency(-99.99, 'USD');
        $this->assertStringContainsString('99', $result);
    }

    #[Test]
    public function currency_respects_decimals(): void
    {
        $result = Number::currency(1234, 'USD', 0);
        $this->assertStringContainsString('1234', $result);
        $this->assertStringNotContainsString('.00', $result);
    }

    // ─── Static: percentage ────────────────────────────────────────────────────

    #[Test]
    public function percentage_formats_integer(): void
    {
        $this->assertSame('85%', Number::percentage(85));
        $this->assertSame('100%', Number::percentage(100));
    }

    #[Test]
    public function percentage_rounds(): void
    {
        $this->assertSame('86%', Number::percentage(85.6));
        $this->assertSame('85.50%', Number::percentage(85.5, 2));
    }

    #[Test]
    public function percentage_handles_zero(): void
    {
        $this->assertSame('0%', Number::percentage(0));
    }

    #[Test]
    public function percentage_handles_negative(): void
    {
        $this->assertSame('-15%', Number::percentage(-15));
    }

    // ─── Static: between ───────────────────────────────────────────────────────

    #[Test]
    public function between_checks_inclusive_range(): void
    {
        $this->assertTrue(Number::between(5, 1, 10));
        $this->assertTrue(Number::between(1, 1, 10));
        $this->assertTrue(Number::between(10, 1, 10));
        $this->assertFalse(Number::between(0, 1, 10));
        $this->assertFalse(Number::between(11, 1, 10));
    }

    #[Test]
    public function between_works_with_floats(): void
    {
        $this->assertTrue(Number::between(5.5, 1.0, 10.0));
        $this->assertFalse(Number::between(0.99, 1.0, 10.0));
    }

    #[Test]
    public function between_handles_equal_bounds(): void
    {
        $this->assertTrue(Number::between(5, 5, 5));
        $this->assertFalse(Number::between(4, 5, 5));
    }

    // ─── Static: clamp ─────────────────────────────────────────────────────────

    #[Test]
    public function clamp_constrains_within_range(): void
    {
        $this->assertSame(5, Number::clamp(5, 1, 10));
        $this->assertSame(1, Number::clamp(0, 1, 10));
        $this->assertSame(10, Number::clamp(20, 1, 10));
    }

    #[Test]
    public function clamp_returns_value_when_in_range(): void
    {
        $this->assertSame(5, Number::clamp(5, 0, 10));
        $this->assertSame(3.14, Number::clamp(3.14, 0, 10));
    }

    #[Test]
    public function clamp_handles_equal_bounds(): void
    {
        $this->assertSame(5, Number::clamp(5, 5, 5));
        $this->assertSame(5, Number::clamp(10, 5, 5));
    }

    // ─── Static: isInt ─────────────────────────────────────────────────────────

    #[Test]
    public function isInt_detects_integers(): void
    {
        $this->assertTrue(Number::isInt(42));
        $this->assertTrue(Number::isInt(0));
        $this->assertTrue(Number::isInt(-1));
        $this->assertFalse(Number::isInt(3.14));
    }

    // ─── Static: isFloat ───────────────────────────────────────────────────────

    #[Test]
    public function isFloat_detects_floats(): void
    {
        $this->assertTrue(Number::isFloat(3.14));
        $this->assertTrue(Number::isFloat(0.0));
        $this->assertTrue(Number::isFloat(-1.5));
        $this->assertFalse(Number::isFloat(42));
    }

    // ─── Static: min / max ─────────────────────────────────────────────────────

    #[Test]
    public function min_returns_smallest(): void
    {
        $this->assertSame(1, Number::min([3, 1, 2]));
        $this->assertSame(-5, Number::min([-1, -5, 0]));
    }

    #[Test]
    public function min_returns_null_for_empty(): void
    {
        $this->assertNull(Number::min([]));
    }

    #[Test]
    public function min_handles_single_element(): void
    {
        $this->assertSame(42, Number::min([42]));
    }

    #[Test]
    public function max_returns_largest(): void
    {
        $this->assertSame(3, Number::max([1, 3, 2]));
        $this->assertSame(0, Number::max([-1, -5, 0]));
    }

    #[Test]
    public function max_returns_null_for_empty(): void
    {
        $this->assertNull(Number::max([]));
    }

    #[Test]
    public function max_handles_single_element(): void
    {
        $this->assertSame(42, Number::max([42]));
    }

    // ─── Static: sum ───────────────────────────────────────────────────────────

    #[Test]
    public function sum_adds_all_values(): void
    {
        $this->assertSame(6, Number::sum([1, 2, 3]));
        $this->assertSame(0.0, Number::sum([1.5, -1.5]));
    }

    #[Test]
    public function sum_returns_zero_for_empty(): void
    {
        $this->assertSame(0, Number::sum([]));
    }

    // ─── Static: average ───────────────────────────────────────────────────────

    #[Test]
    public function average_calculates_mean(): void
    {
        $this->assertSame(2.0, Number::average([1, 2, 3]));
        $this->assertSame(2.5, Number::average([2, 3]));
    }

    #[Test]
    public function average_returns_null_for_empty(): void
    {
        $this->assertNull(Number::average([]));
    }

    #[Test]
    public function average_handles_single_value(): void
    {
        $this->assertSame(42.0, Number::average([42]));
    }

    // ─── Static: range ─────────────────────────────────────────────────────────

    #[Test]
    public function range_generates_ascending(): void
    {
        $this->assertSame([1, 2, 3, 4, 5], Number::range(1, 5));
    }

    #[Test]
    public function range_generates_descending(): void
    {
        $this->assertSame([5, 4, 3, 2, 1], Number::range(5, 1));
    }

    #[Test]
    public function range_handles_single_value(): void
    {
        $this->assertSame([3], Number::range(3, 3));
    }

    // ─── Builder: factory and value access ─────────────────────────────────────

    #[Test]
    public function make_creates_instance_with_int(): void
    {
        $number = Number::make(42);
        $this->assertSame(42, $number->value());
    }

    #[Test]
    public function make_creates_instance_with_float(): void
    {
        $number = Number::make(3.14);
        $this->assertSame(3.14, $number->value());
    }

    #[Test]
    public function toString_returns_value(): void
    {
        $this->assertSame('42', (string) Number::make(42));
        $this->assertSame('3.14', (string) Number::make(3.14));
    }

    #[Test]
    public function jsonSerialize_returns_value(): void
    {
        $this->assertSame(42, Number::make(42)->jsonSerialize());
        $this->assertSame(3.14, Number::make(3.14)->jsonSerialize());
    }

    #[Test]
    public function json_encode_works(): void
    {
        $this->assertSame('42', json_encode(Number::make(42)));
        $this->assertSame('3.14', json_encode(Number::make(3.14)));
    }

    // ─── Builder: immutability ─────────────────────────────────────────────────

    #[Test]
    public function builder_methods_return_new_instances(): void
    {
        $original = Number::make(10);
        $modified = $original->add(5);

        $this->assertNotSame($original, $modified);
        $this->assertSame(10, $original->value());
        $this->assertSame(15, $modified->value());
    }

    #[Test]
    public function original_unchanged_after_chain(): void
    {
        $original = Number::make(10);
        $original->add(5)->multiply(2)->round();

        $this->assertSame(10, $original->value());
    }

    #[Test]
    public function clone_method_creates_independent_copy(): void
    {
        $original = Number::make(42);
        $clone = $original->clone();

        $this->assertSame($original->value(), $clone->value());
        $this->assertNotSame($original, $clone);
    }

    // ─── Builder: add ──────────────────────────────────────────────────────────

    #[Test]
    public function add_increases_value(): void
    {
        $this->assertSame(15, Number::make(10)->add(5)->value());
        $this->assertSame(10.5, Number::make(10)->add(0.5)->value());
    }

    #[Test]
    public function add_handles_negative(): void
    {
        $this->assertSame(5, Number::make(10)->add(-5)->value());
    }

    // ─── Builder: subtract ─────────────────────────────────────────────────────

    #[Test]
    public function subtract_decreases_value(): void
    {
        $this->assertSame(5, Number::make(10)->subtract(5)->value());
        $this->assertSame(9.5, Number::make(10)->subtract(0.5)->value());
    }

    #[Test]
    public function subtract_handles_negative(): void
    {
        $this->assertSame(15, Number::make(10)->subtract(-5)->value());
    }

    // ─── Builder: multiply ─────────────────────────────────────────────────────

    #[Test]
    public function multiply_scales_value(): void
    {
        $this->assertSame(20, Number::make(10)->multiply(2)->value());
        $this->assertSame(2.5, Number::make(5)->multiply(0.5)->value());
    }

    #[Test]
    public function multiply_by_zero(): void
    {
        $this->assertSame(0, Number::make(10)->multiply(0)->value());
    }

    #[Test]
    public function multiply_by_negative(): void
    {
        $this->assertSame(-10, Number::make(10)->multiply(-1)->value());
    }

    // ─── Builder: divide ───────────────────────────────────────────────────────

    #[Test]
    public function divide_reduces_value(): void
    {
        $this->assertSame(5, Number::make(10)->divide(2)->value());
        $this->assertSame(2.5, Number::make(10)->divide(4)->value());
    }

    #[Test]
    public function divide_by_negative(): void
    {
        $this->assertSame(-5, Number::make(10)->divide(-2)->value());
    }

    // ─── Builder: round ────────────────────────────────────────────────────────

    #[Test]
    public function round_rounds_value(): void
    {
        $this->assertSame(3.0, Number::make(3.4)->round()->value());
        $this->assertSame(4.0, Number::make(3.5)->round()->value());
        $this->assertSame(3.14, Number::make(3.14159)->round(2)->value());
    }

    #[Test]
    public function round_handles_negative(): void
    {
        $this->assertSame(-4.0, Number::make(-3.5)->round()->value());
    }

    // ─── Builder: floor ────────────────────────────────────────────────────────

    #[Test]
    public function floor_rounds_down(): void
    {
        $this->assertSame(3.0, Number::make(3.9)->floor()->value());
        $this->assertSame(-4.0, Number::make(-3.1)->floor()->value());
    }

    // ─── Builder: ceil ─────────────────────────────────────────────────────────

    #[Test]
    public function ceil_rounds_up(): void
    {
        $this->assertSame(4.0, Number::make(3.1)->ceil()->value());
        $this->assertSame(-3.0, Number::make(-3.9)->ceil()->value());
    }

    // ─── Builder: abs ──────────────────────────────────────────────────────────

    #[Test]
    public function abs_returns_absolute_value(): void
    {
        $this->assertSame(5, Number::make(-5)->abs()->value());
        $this->assertSame(5, Number::make(5)->abs()->value());
        $this->assertSame(3.14, Number::make(-3.14)->abs()->value());
    }

    // ─── Builder: format (instance) ────────────────────────────────────────────

    #[Test]
    public function format_instance_formats_value(): void
    {
        $this->assertSame('1,234', Number::make(1234)->format());
        $this->assertSame('1,234.56', Number::make(1234.56)->format(2));
    }

    #[Test]
    public function format_instance_returns_string(): void
    {
        $result = Number::make(1234)->format();
        $this->assertIsString($result);
    }

    // ─── Builder: chaining ─────────────────────────────────────────────────────

    #[Test]
    public function chaining_produces_expected_result(): void
    {
        $result = Number::make(10)
            ->add(5)
            ->multiply(2)
            ->subtract(5)
            ->round(0);

        $this->assertSame(25.0, $result->value());
    }

    #[Test]
    public function chaining_with_abs_and_floor(): void
    {
        $result = Number::make(-10)
            ->multiply(3)
            ->abs()
            ->floor();

        $this->assertSame(30.0, $result->value());
    }

    #[Test]
    public function chaining_with_divide_and_round(): void
    {
        $result = Number::make(10)
            ->divide(3)
            ->round(2);

        $this->assertSame(3.33, $result->value());
    }
}
