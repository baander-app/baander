<?php

namespace App\Primitives;

use App\Primitives\Traits\ImmutableBuilder;

/**
 * Handle locale string delimiters for marking and identifying translatable strings.
 *
 * This class provides utilities to add and remove special delimiters around strings
 * to mark them as locale/translation strings in your application.
 *
 * Instance methods are immutable — they return new instances.
 *
 * @example
 * LocaleString::delimit('Hello World') // "$_︸_$Hello World$_︸_$"
 * LocaleString::isLocaleString("$_︸_$Hello$_︸_$") // true
 * LocaleString::removeDelimiters("$_︸_$Hello$_︸_$") // "Hello"
 */
class LocaleString
{
    use ImmutableBuilder;

    public const string LOCALE_STRING_DELIMITER = '$_︸_$';

    /**
     * Add delimiters to a string to mark it as a locale string.
     */
    public static function delimit(string $value): string
    {
        return self::applyDelimiter($value);
    }

    /**
     * Remove delimiters from a locale string.
     */
    public static function removeDelimiters(string $value): string
    {
        return self::stripDelimiter($value);
    }

    /**
     * Check if a string is marked as a locale string.
     */
    public static function isLocaleString(string $value): bool
    {
        return str_contains($value, self::LOCALE_STRING_DELIMITER);
    }

    /**
     * Extract the core value from a string, removing delimiters if present.
     */
    public static function extract(string $value): string
    {
        return self::isLocaleString($value)
            ? self::removeDelimiters($value)
            : $value;
    }

    /**
     * Create a new LocaleString instance.
     */
    public static function make(string $value = ''): self
    {
        return new self($value);
    }

    protected function __construct(private readonly ?string $value = null) {}

    /**
     * Set the value and optionally delimit it, returning a new instance.
     */
    public function set(string $value, bool $delimit = false): self
    {
        return new self($delimit ? self::applyDelimiter($value) : $value);
    }

    /**
     * Get the current value.
     */
    public function get(): string
    {
        return $this->value ?? '';
    }

    /**
     * Get the value without delimiters.
     */
    public function getClean(): string
    {
        return self::extract($this->get());
    }

    /**
     * Check if the current value is delimited.
     */
    public function isDelimited(): bool
    {
        return self::isLocaleString($this->get());
    }

    /**
     * Add delimiters to the current value, returning a new instance.
     */
    public function addDelimiters(): self
    {
        if ($this->isDelimited()) {
            return clone $this;
        }

        return new self(self::applyDelimiter($this->get()));
    }

    /**
     * Remove delimiters from the current value, returning a new instance.
     */
    public function stripDelimiters(): self
    {
        return new self(self::stripDelimiter($this->get()));
    }

    /**
     * Convert to string (returns the delimited value).
     */
    public function __toString(): string
    {
        return $this->get();
    }

    private static function applyDelimiter(string $value): string
    {
        return self::LOCALE_STRING_DELIMITER . $value . self::LOCALE_STRING_DELIMITER;
    }

    private static function stripDelimiter(string $value): string
    {
        return str_replace(self::LOCALE_STRING_DELIMITER, '', $value);
    }
}
