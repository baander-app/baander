<?php

namespace App\Primitives;

use App\Primitives\Traits\ForwardsCalls;
use App\Primitives\Traits\ImmutableBuilder;
use JsonSerializable;
use Stringable;

/**
 * Immutable number manipulation with fluent builder pattern.
 *
 * All dynamic methods can be called statically where the first
 * argument becomes the number value: Number::add(5, 3) → Number(8)
 *
 * @method self add(int|float $value) Add
 * @method self subtract(int|float $value) Subtract
 * @method self multiply(int|float $value) Multiply
 * @method self divide(int|float $value) Divide
 * @method self round(int $precision = 0) Round
 * @method self floor() Floor
 * @method self ceil() Ceil
 * @method self abs() Absolute value
 * @method string format(int $decimals = 0, ?string $decimalSeparator = '.', ?string $thousandsSeparator = ',') Format
 * @method string currency(string $currency = 'USD', int $decimals = 2) Currency format
 * @method string percentage(int $decimals = 0) Percentage
 * @method bool between(int|float $min, int|float $max) Between range
 * @method int|float clamp(int|float $min, int|float $max) Clamp to range
 * @method bool isInt() Is integer
 * @method bool isFloat() Is float
 * @method array range(int $end) Range from value to end
 *
 * @method static self add(int|float $value, int|float $operand) Add
 * @method static self subtract(int|float $value, int|float $operand) Subtract
 * @method static self multiply(int|float $value, int|float $operand) Multiply
 * @method static self divide(int|float $value, int|float $operand) Divide
 * @method static self round(int|float $value, int $precision = 0) Round
 * @method static self floor(int|float $value) Floor
 * @method static self ceil(int|float $value) Ceil
 * @method static self abs(int|float $value) Absolute value
 * @method static string format(int|float $value, int $decimals = 0, ?string $decimalSeparator = '.', ?string $thousandsSeparator = ',') Format
 * @method static string currency(int|float $value, string $currency = 'USD', int $decimals = 2) Currency format
 * @method static string percentage(int|float $value, int $decimals = 0) Percentage
 * @method static bool between(int|float $value, int|float $min, int|float $max) Between range
 * @method static int|float clamp(int|float $value, int|float $min, int|float $max) Clamp to range
 * @method static bool isInt(int|float $value) Is integer
 * @method static bool isFloat(int|float $value) Is float
 * @method static array range(int $value, int $end) Range from value to end
 */
class Number implements Stringable, JsonSerializable
{
    use ForwardsCalls;
    use ImmutableBuilder;

    protected function __construct(private int|float $value)
    {
    }

    public static function make(int|float $number): static
    {
        return new static($number);
    }

    // ─── Static-Only (array-based utilities) ────────────────────────────────────

    public static function min(array $values): int|float|null
    {
        if ($values === []) {
            return null;
        }

        return min($values);
    }

    public static function max(array $values): int|float|null
    {
        if ($values === []) {
            return null;
        }

        return max($values);
    }

    public static function sum(array $values): int|float
    {
        return array_sum($values);
    }

    public static function average(array $values): float|null
    {
        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    public function value(): int|float
    {
        return $this->value;
    }

    // ─── Interfaces ─────────────────────────────────────────────────────────────

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function jsonSerialize(): int|float
    {
        return $this->value;
    }

    // ─── Magic Methods ──────────────────────────────────────────────────────────

    public static function __callStatic(string $method, array $parameters): mixed
    {
        if (count($parameters) === 0) {
            throw new \BadMethodCallException("Method {$method}() requires at least one argument on " . static::class);
        }

        return static::make(array_shift($parameters))->{$method}(...$parameters);
    }

    public function __call(string $method, array $parameters): mixed
    {
        $impl = 'do' . ucfirst($method);

        if (! method_exists($this, $impl)) {
            static::throwBadMethodCallException($method);
        }

        return $this->$impl(...$parameters);
    }

    // ─── Private Implementation ──────────────────────────────────────────────────

    // ── Builders ────────────────────────────────────────────────────────────────

    private function doAdd(int|float $value): static
    {
        return $this->clone()->withValue($this->value + $value);
    }

    private function doSubtract(int|float $value): static
    {
        return $this->clone()->withValue($this->value - $value);
    }

    private function doMultiply(int|float $value): static
    {
        return $this->clone()->withValue($this->value * $value);
    }

    private function doDivide(int|float $value): static
    {
        return $this->clone()->withValue($this->value / $value);
    }

    private function doRound(int $precision = 0): static
    {
        return $this->clone()->withValue(round($this->value, $precision));
    }

    private function doFloor(): static
    {
        return $this->clone()->withValue(floor($this->value));
    }

    private function doCeil(): static
    {
        return $this->clone()->withValue(ceil($this->value));
    }

    private function doAbs(): static
    {
        return $this->clone()->withValue(abs($this->value));
    }

    // ── Inspectors ─────────────────────────────────────────────────────────────

    private function doFormat(int $decimals = 0, ?string $decimalSeparator = '.', ?string $thousandsSeparator = ','): string
    {
        return number_format($this->value, $decimals, $decimalSeparator ?? '.', $thousandsSeparator ?? ',');
    }

    private function doCurrency(string $currency = 'USD', int $decimals = 2): string
    {
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter(\Locale::getDefault() ?: 'en_US', \NumberFormatter::CURRENCY);
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);

            return $formatter->formatCurrency($this->value, $currency);
        }

        $symbols = [
            'USD' => '$', 'EUR' => "\u{20AC}", 'GBP' => "\u{00A3}", 'JPY' => "\u{00A5}",
            'CAD' => 'C$', 'AUD' => 'A$', 'CHF' => 'CHF', 'CNY' => "\u{00A5}",
            'DKK' => 'kr', 'SEK' => 'kr', 'NOK' => 'kr', 'BRL' => 'R$',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';

        return $symbol . number_format($this->value, $decimals, '.', ',');
    }

    private function doPercentage(int $decimals = 0): string
    {
        return number_format($this->value, $decimals, '.', ',') . '%';
    }

    private function doBetween(int|float $min, int|float $max): bool
    {
        return $this->value >= $min && $this->value <= $max;
    }

    private function doClamp(int|float $min, int|float $max): int|float
    {
        return max($min, min($max, $this->value));
    }

    private function doIsInt(): bool
    {
        return is_int($this->value);
    }

    private function doIsFloat(): bool
    {
        return is_float($this->value);
    }

    private function doRange(int $end): array
    {
        return range($this->value, $end);
    }

    // ─── Private Helpers ───────────────────────────────────────────────────────

    protected function withValue(int|float $value): static
    {
        $this->value = $value;

        return $this;
    }
}
