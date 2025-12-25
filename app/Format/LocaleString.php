<?php

namespace App\Format;

/**
 * Handle locale string delimiters for marking and identifying translatable strings.
 *
 * This class provides utilities to add and remove special delimiters around strings
 * to mark them as locale/translation strings in your application.
 *
 * @example
 * LocaleString::delimit('Hello World') // "$_︸_$Hello World$_︸_$"
 * LocaleString::isLocaleString("$_︸_$Hello$_︸_$") // true
 * LocaleString::removeDelimiters("$_︸_$Hello$_︸_$") // "Hello"
 */
class LocaleString
{
    public const string LOCALE_STRING_DELIMITER = '$_︸_$';

    /**
     * Add delimiters to a string to mark it as a locale string.
     *
     * @param string $value The string to delimit
     * @return string The delimited string
     *
     * @example LocaleString::delimit('Hello') // "$_︸_$Hello$_︸_$"
     */
    public static function delimit(string $value): string
    {
        return self::applyDelimiter($value);
    }

    /**
     * Remove delimiters from a locale string.
     *
     * @param string $value The delimited string
     * @return string The string without delimiters
     *
     * @example LocaleString::removeDelimiters("$_︸_$Hello$_︸_$") // "Hello"
     */
    public static function removeDelimiters(string $value): string
    {
        return self::stripDelimiter($value);
    }

    /**
     * Check if a string is marked as a locale string.
     *
     * @param string $value The string to check
     * @return bool True if the string contains locale delimiters
     *
     * @example LocaleString::isLocaleString("$_︸_$Hello$_︸_$") // true
     * @example LocaleString::isLocaleString("Hello") // false
     */
    public static function isLocaleString(string $value): bool
    {
        return str_contains($value, self::LOCALE_STRING_DELIMITER);
    }

    /**
     * Extract the core value from a string, removing delimiters if present.
     *
     * If the string is not a locale string, it's returned unchanged.
     *
     * @param string $value The string to extract from
     * @return string The extracted value
     */
    public static function extract(string $value): string
    {
        return self::isLocaleString($value)
            ? self::removeDelimiters($value)
            : $value;
    }

    /**
     * Create a new LocaleString instance with the given value.
     *
     * @param string $value The string to wrap
     * @return self A new LocaleString instance
     */
    public static function make(string $value): self
    {
        return new self($value);
    }

    /**
     * Create a new instance.
     *
     * @param string|null $value Optional initial value
     */
    final public function __construct(private ?string $value = null)
    {
    }

    /**
     * Set the value and optionally delimit it.
     *
     * @param string $value The value to set
     * @param bool $delimit Whether to add delimiters
     * @return self
     */
    public function set(string $value, bool $delimit = false): self
    {
        $this->value = $delimit ? self::applyDelimiter($value) : $value;

        return $this;
    }

    /**
     * Get the current value.
     *
     * @return string The current value
     */
    public function get(): string
    {
        return $this->value ?? '';
    }

    /**
     * Get the value without delimiters.
     *
     * @return string The value without delimiters
     */
    public function getClean(): string
    {
        return self::extract($this->get());
    }

    /**
     * Check if the current value is delimited.
     *
     * @return bool True if the value has delimiters
     */
    public function isDelimited(): bool
    {
        return self::isLocaleString($this->get());
    }

    /**
     * Add delimiters to the current value.
     *
     * @return self
     */
    public function addDelimiters(): self
    {
        if (!$this->isDelimited()) {
            $this->value = self::applyDelimiter($this->get());
        }

        return $this;
    }

    /**
     * Remove delimiters from the current value.
     *
     * @return self
     */
    public function stripDelimiters(): self
    {
        $this->value = self::stripDelimiter($this->get());

        return $this;
    }

    /**
     * Convert to string (returns the delimited value).
     *
     * @return string
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
