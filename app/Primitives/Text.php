<?php

namespace App\Primitives;

use App\Primitives\Traits\ImmutableBuilder;
use JsonSerializable;
use Stringable;

class Text implements Stringable, JsonSerializable
{
    use ImmutableBuilder;

    /**
     * Methods where builder calls delegate to private do* implementations.
     * PHP won't invoke __call when a public static method with the same name exists.
     */
    private const array STATIC_DELEGATES = ['studly', 'snake', 'before'];

    protected function __construct(private string $value)
    {
    }

    public static function make(string $string): static
    {
        return new static($string);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * Delegate instance calls to private do* implementations for overlapping method names.
     */
    public function __call(string $method, array $parameters): static
    {
        if (in_array($method, self::STATIC_DELEGATES, true)) {
            $impl = 'do' . ucfirst($method);

            return $this->clone()->withValue(self::$impl($this->value, ...$parameters));
        }

        throw new \BadMethodCallException("Method $method does not exist on " . static::class);
    }

    /**
     * Delegate static calls to private do* implementations for overlapping method names.
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        if (in_array($method, self::STATIC_DELEGATES, true)) {
            $impl = 'do' . ucfirst($method);

            return self::$impl(...$parameters);
        }

        throw new \BadMethodCallException("Method $method does not exist on " . static::class);
    }

    // ─── Builder Methods (all return new instances) ─────────────────────────

    public function lower(): static
    {
        return $this->clone()->withValue(mb_strtolower($this->value));
    }

    public function upper(): static
    {
        return $this->clone()->withValue(mb_strtoupper($this->value));
    }

    public function title(): static
    {
        return $this->clone()->withValue(mb_convert_case($this->value, MB_CASE_TITLE, 'UTF-8'));
    }

    public function trim(string $characters = " \t\n\r\0\x0B"): static
    {
        return $this->clone()->withValue(trim($this->value, $characters));
    }

    public function ltrim(string $characters = " \t\n\r\0\x0B"): static
    {
        return $this->clone()->withValue(ltrim($this->value, $characters));
    }

    public function rtrim(string $characters = " \t\n\r\0\x0B"): static
    {
        return $this->clone()->withValue(rtrim($this->value, $characters));
    }

    public function replace(string $search, string $replace): static
    {
        return $this->clone()->withValue(str_replace($search, $replace, $this->value));
    }

    public function replaceLast(string $search, string $replace): static
    {
        if ($search === '') {
            return $this->clone();
        }

        $position = mb_strrpos($this->value, $search);

        if ($position === false) {
            return $this->clone();
        }

        return $this->clone()->withValue(
            mb_substr($this->value, 0, $position) . $replace . mb_substr($this->value, $position + mb_strlen($search))
        );
    }

    public function after(string $search): static
    {
        return $this->clone()->withValue(
            str_contains($this->value, $search)
                ? mb_substr($this->value, mb_strpos($this->value, $search) + mb_strlen($search))
                : $this->value
        );
    }

    public function substr(int $start, ?int $length = null): static
    {
        return $this->clone()->withValue(
            $length === null
                ? mb_substr($this->value, $start)
                : mb_substr($this->value, $start, $length)
        );
    }

    public function prepend(string ...$values): static
    {
        return $this->clone()->withValue(implode('', $values) . $this->value);
    }

    public function append(string ...$values): static
    {
        return $this->clone()->withValue($this->value . implode('', $values));
    }

    public function camel(): static
    {
        return $this->clone()->withValue(self::studlyToCamel(self::doStudly($this->value)));
    }

    public function kebab(): static
    {
        return $this->clone()->withValue(self::doSnake($this->value, '-'));
    }

    // ─── Static Methods ─────────────────────────────────────────────────────

    public static function slug(string $string, string $separator = '-'): string
    {
        $string = self::ascii($string);
        $string = preg_replace('/[^a-zA-Z0-9\s-]/', '', $string);
        $string = preg_replace('/[\s-]+/', ' ', $string);
        $string = trim($string);

        return $string === '' ? '' : str_replace(' ', $separator, mb_strtolower($string));
    }

    public static function random(int $length = 16): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $result;
    }

    public static function contains(string $haystack, string|array $needles): bool
    {
        return array_any((array)$needles, fn($needle) => $needle !== '' && str_contains($haystack, $needle));
    }

    public static function endsWith(string $haystack, string|array $needles): bool
    {
        return array_any((array)$needles, fn($needle) => $needle !== '' && str_ends_with($haystack, $needle));

    }

    public static function startsWith(string $haystack, string|array $needles): bool
    {
        return array_any((array)$needles, fn($needle) => $needle !== '' && str_starts_with($haystack, $needle));
    }

    public static function uuid(): string
    {
        if (class_exists(\Ramsey\Uuid\Uuid::class)) {
            return \Ramsey\Uuid\Uuid::uuid4()->toString();
        }

        if (function_exists('com_create_guid')) {
            return trim(com_create_guid(), '{}');
        }

        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }

        $position = mb_strpos($subject, $search);

        if ($position === false) {
            return $subject;
        }

        return mb_substr($subject, 0, $position) . $replace . mb_substr($subject, $position + mb_strlen($search));
    }

    public static function ascii(string $value, string $language = 'en'): string
    {
        $transliterations = [
            'latin' => [
                'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE',
                'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
                'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
                'Ð' => 'D', 'Ñ' => 'N',
                'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Œ' => 'OE',
                'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
                'Ý' => 'Y', 'Þ' => 'TH',
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
                'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
                'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
                'ð' => 'd', 'ñ' => 'n',
                'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
                'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
                'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y',
            ],
            'de' => [
                'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
                'Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE',
                'ß' => 'ss',
            ],
        ];

        if (isset($transliterations[$language])) {
            $value = strtr($value, $transliterations[$language]);
            // Remove language-overridden keys from Latin fallback
            $latinTable = array_diff_key($transliterations['latin'], $transliterations[$language]);
        } else {
            $latinTable = $transliterations['latin'];
        }

        $value = strtr($value, $latinTable);
        return preg_replace('/[^\x20-\x7E]/u', '', $value) ?? $value;
    }

    public static function between(mixed $str, string $startingWord, string $endingWord): ?string
    {
        if (!is_string($str) || $str === '') {
            return null;
        }

        try {
            $substringStart = mb_strpos($str, $startingWord);
            if ($substringStart === false || $substringStart <= 0) {
                return null;
            }

            $substringStart += mb_strlen($startingWord);
            $size = mb_strpos($str, $endingWord, $substringStart);

            if ($size === false) {
                return null;
            }

            $size -= $substringStart;

            if ($size <= 0) {
                return null;
            }

            return mb_substr($str, $substringStart, $size);
        } catch (\Exception) {
            return null;
        }
    }

    public static function safe(mixed $str): ?string
    {
        if (!is_string($str) || $str === '') {
            return null;
        }

        return strip_tags(str_replace("\x00", '', $str));
    }

    public static function convertToUtf8(mixed $str): ?string
    {
        if (!is_string($str) || $str === '') {
            return null;
        }

        $encoding = mb_detect_encoding($str);

        return mb_convert_encoding($str, 'UTF-8', $encoding);
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    private static function doStudly(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', '', $value);
    }

    private static function doSnake(string $value, string $delimiter = '_'): string
    {
        // Normalize existing separators to spaces
        $value = str_replace(['-', '_'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        // Insert space before uppercase letters (camelCase/studlyCase handling)
        if (preg_match('/[A-Z]/u', $value)) {
            $value = preg_replace('/(.)(?=[A-Z])/u', '$1 ', $value);
            $value = preg_replace('/\s+/u', ' ', $value);
        }

        // Replace spaces with delimiter and lowercase
        $value = str_replace(' ', $delimiter, $value);

        return mb_strtolower($value, 'UTF-8');
    }

    private static function doBefore(string $subject, string $search): string
    {
        return str_contains($subject, $search)
            ? mb_substr($subject, 0, mb_strpos($subject, $search))
            : $subject;
    }

    protected function withValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    private static function studlyToCamel(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return mb_strtolower(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }
}
