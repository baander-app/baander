<?php

namespace App\Primitives;

use App\Primitives\Traits\ForwardsCalls;
use App\Primitives\Traits\ImmutableBuilder;
use JsonSerializable;
use Normalizer;
use Stringable;

/**
 * Immutable string manipulation with fluent builder pattern.
 *
 * All dynamic methods can be called statically where the first
 * argument becomes the string value: Text::upper('hello') → Text('HELLO')
 *
 * @method self upper() Uppercase
 * @method self lower() Lowercase
 * @method self title() Title case
 * @method self trim(string $characters = " \t\n\r\0\x0B") Trim characters
 * @method self ltrim(string $characters = " \t\n\r\0\x0B") Trim left
 * @method self rtrim(string $characters = " \t\n\r\0\x0B") Trim right
 * @method self replace(string|array $search, string|array $replace) Replace all
 * @method self replaceFirst(string $search, string $replace) Replace first
 * @method self replaceLast(string $search, string $replace) Replace last
 * @method self after(string $search) Part after search
 * @method self before(string $search) Part before search
 * @method self substr(int $start, int|null $length = null) Substring
 * @method self prepend(string ...$values) Prepend values
 * @method self append(string ...$values) Append values
 * @method self camel() camelCase
 * @method self kebab() kebab-case
 * @method self studly() StudlyCase
 * @method self snake(string $delimiter = '_') snake_case
 * @method self slug(string $separator = '-') URL-friendly slug
 * @method self ascii(string $language = 'en') Transliterate to ASCII
 * @method bool contains(string|array $needles) Contains substring
 * @method bool endsWith(string|array $needles) Ends with
 * @method bool startsWith(string|array $needles) Starts with
 * @method string|null between(string $start, string $end) Extract between
 * @method string|null safe() Strip HTML tags
 * @method string|null convertToUtf8() Convert to UTF-8
 *
 * @method static self upper(string $value) Uppercase
 * @method static self lower(string $value) Lowercase
 * @method static self title(string $value) Title case
 * @method static self trim(string $value, string $characters = " \t\n\r\0\x0B") Trim characters
 * @method static self ltrim(string $value, string $characters = " \t\n\r\0\x0B") Trim left
 * @method static self rtrim(string $value, string $characters = " \t\n\r\0\x0B") Trim right
 * @method static self replace(string $value, string|array $search, string|array $replace) Replace all
 * @method static self replaceFirst(string $value, string $search, string $replace) Replace first
 * @method static self replaceLast(string $value, string $search, string $replace) Replace last
 * @method static self after(string $value, string $search) Part after search
 * @method static self before(string $value, string $search) Part before search
 * @method static self substr(string $value, int $start, int|null $length = null) Substring
 * @method static self prepend(string $value, string ...$values) Prepend values
 * @method static self append(string $value, string ...$values) Append values
 * @method static self camel(string $value) camelCase
 * @method static self kebab(string $value) kebab-case
 * @method static self studly(string $value) StudlyCase
 * @method static self snake(string $value, string $delimiter = '_') snake_case
 * @method static self slug(string $value, string $separator = '-') URL-friendly slug
 * @method static self ascii(string $value, string $language = 'en') Transliterate to ASCII
 * @method static bool contains(string $value, string|array $needles) Contains substring
 * @method static bool endsWith(string $value, string|array $needles) Ends with
 * @method static bool startsWith(string $value, string|array $needles) Starts with
 * @method static string|null between(string $value, string $start, string $end) Extract between
 * @method static string|null safe(string $value) Strip HTML tags
 * @method static string|null convertToUtf8(string $value) Convert to UTF-8
 */
class Text implements Stringable, JsonSerializable
{
    use ForwardsCalls;
    use ImmutableBuilder;

    protected function __construct(protected string $value)
    {
    }

    public static function make(string $string): static
    {
        return new static($string);
    }

    // ─── Static-Only ────────────────────────────────────────────────────────────

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

    // ─── Accessors ───────────────────────────────────────────────────────────────

    public function value(): string
    {
        return $this->value;
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    public function isNotEmpty(): bool
    {
        return $this->value !== '';
    }

    // ─── Interfaces ─────────────────────────────────────────────────────────────

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
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

    private function doUpper(): static
    {
        return $this->clone()->withValue(mb_strtoupper($this->value));
    }

    private function doLower(): static
    {
        return $this->clone()->withValue(mb_strtolower($this->value));
    }

    private function doTitle(): static
    {
        return $this->clone()->withValue(mb_convert_case($this->value, MB_CASE_TITLE, 'UTF-8'));
    }

    private function doTrim(string $characters = " \t\n\r\0\x0B"): static
    {
        return $this->clone()->withValue(trim($this->value, $characters));
    }

    private function doLtrim(string $characters = " \t\n\r\0\x0B"): static
    {
        return $this->clone()->withValue(ltrim($this->value, $characters));
    }

    private function doRtrim(string $characters = " \t\n\r\0\x0B"): static
    {
        return $this->clone()->withValue(rtrim($this->value, $characters));
    }

    private function doReplace(string|array $search, string|array $replace): static
    {
        return $this->clone()->withValue(str_replace($search, $replace, $this->value));
    }

    private function doReplaceFirst(string $search, string $replace): static
    {
        if ($search === '') {
            return $this->clone();
        }

        $position = mb_strpos($this->value, $search);

        if ($position === false) {
            return $this->clone();
        }

        return $this->clone()->withValue(
            mb_substr($this->value, 0, $position) . $replace . mb_substr($this->value, $position + mb_strlen($search))
        );
    }

    private function doReplaceLast(string $search, string $replace): static
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

    private function doAfter(string $search): static
    {
        return str_contains($this->value, $search)
            ? $this->clone()->withValue(mb_substr($this->value, mb_strpos($this->value, $search) + mb_strlen($search)))
            : $this->clone();
    }

    private function doBefore(string $search): static
    {
        return str_contains($this->value, $search)
            ? $this->clone()->withValue(mb_substr($this->value, 0, mb_strpos($this->value, $search)))
            : $this->clone();
    }

    private function doSubstr(int $start, ?int $length = null): static
    {
        return $this->clone()->withValue(
            $length === null
                ? mb_substr($this->value, $start)
                : mb_substr($this->value, $start, $length)
        );
    }

    private function doPrepend(string ...$values): static
    {
        return $this->clone()->withValue(implode('', $values) . $this->value);
    }

    private function doAppend(string ...$values): static
    {
        return $this->clone()->withValue($this->value . implode('', $values));
    }

    private function doCamel(): static
    {
        return $this->clone()->withValue(self::studlyToCamel(self::computeStudly($this->value)));
    }

    private function doKebab(): static
    {
        return $this->clone()->withValue(self::computeSnake($this->value, '-'));
    }

    private function doStudly(): static
    {
        return $this->clone()->withValue(self::computeStudly($this->value));
    }

    private function doSnake(string $delimiter = '_'): static
    {
        return $this->clone()->withValue(self::computeSnake($this->value, $delimiter));
    }

    private function doSlug(string $separator = '-'): static
    {
        $value = self::computeAscii($this->value);
        $value = preg_replace('/[^a-zA-Z0-9\s-]/', '', $value);
        $value = preg_replace('/[\s-]+/', ' ', $value);
        $value = trim($value);

        return $this->clone()->withValue(
            $value === '' ? '' : str_replace(' ', $separator, mb_strtolower($value))
        );
    }

    private function doAscii(string $language = 'en'): static
    {
        return $this->clone()->withValue(self::computeAscii($this->value, $language));
    }

    private function doContains(string|array $needles): bool
    {
        return array_any((array) $needles, fn ($needle) => $needle !== '' && str_contains($this->value, $needle));
    }

    private function doEndsWith(string|array $needles): bool
    {
        return array_any((array) $needles, fn ($needle) => $needle !== '' && str_ends_with($this->value, $needle));
    }

    private function doStartsWith(string|array $needles): bool
    {
        return array_any((array) $needles, fn ($needle) => $needle !== '' && str_starts_with($this->value, $needle));
    }

    private function doBetween(string $startingWord, string $endingWord): ?string
    {
        if ($this->value === '') {
            return null;
        }

        try {
            $substringStart = mb_strpos($this->value, $startingWord);
            if ($substringStart === false || $substringStart <= 0) {
                return null;
            }

            $substringStart += mb_strlen($startingWord);
            $size = mb_strpos($this->value, $endingWord, $substringStart);

            if ($size === false) {
                return null;
            }

            $size -= $substringStart;

            if ($size <= 0) {
                return null;
            }

            return mb_substr($this->value, $substringStart, $size);
        } catch (\Exception) {
            return null;
        }
    }

    private function doSafe(): ?string
    {
        if ($this->value === '') {
            return null;
        }

        return strip_tags(str_replace("\x00", '', $this->value));
    }

    /**
     * Comprehensive sanitization for user-provided text.
     * Removes HTML tags, XSS patterns, and normalizes Unicode.
     */
    public static function sanitize(?string $str): ?string
    {
        if (! $str) {
            return null;
        }

        $str = str_replace("\0", '', $str);
        $str = Normalizer::normalize($str, Normalizer::FORM_C);
        $str = htmlentities($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $str = strip_tags($str);

        $xssPatterns = [
            '/javascript:/i',
            '/data:text\/html/i',
            '/vbscript:/i',
            '/onload\s*=/i',
            '/onerror\s*=/i',
            '/onclick\s*=/i',
            '/onmouseover\s*=/i',
            '/<script\b/i',
            '/<iframe\b/i',
            '/<object\b/i',
            '/<embed\b/i',
        ];

        foreach ($xssPatterns as $pattern) {
            $str = preg_replace($pattern, '', $str);
        }

        return trim($str);
    }

    /**
     * Strict sanitization for metadata fields (title, artist, album, genre).
     * Removes HTML, XSS, and normalizes whitespace.
     */
    public static function sanitizeMetadata(?string $str): ?string
    {
        if (! $str) {
            return null;
        }

        $str = self::sanitize($str);
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
        $str = preg_replace('/\s+/', ' ', $str);

        return $str;
    }

    /**
     * Sanitization for lyrics — preserves formatting while removing scripts.
     * Keeps newlines, verse markers, and section headers.
     */
    public static function sanitizeLyrics(?string $str): ?string
    {
        if (! $str) {
            return null;
        }

        $str = str_replace("\0", '', $str);
        $str = Normalizer::normalize($str, Normalizer::FORM_C);
        $str = htmlentities($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $dangerousPatterns = [
            '/<script\b[^>]*>.*?<\/script>/is',
            '/javascript:/i',
            '/vbscript:/i',
            '/on(load|error|click|mouseover)\s*=/i',
            '/<iframe\b/i',
            '/<object\b/i',
            '/<embed\b/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            $str = preg_replace($pattern, '', $str);
        }

        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);

        return $str;
    }

    private function doConvertToUtf8(): ?string
    {
        if ($this->value === '') {
            return null;
        }

        $encoding = mb_detect_encoding($this->value);

        return mb_convert_encoding($this->value, 'UTF-8', $encoding);
    }

    // ─── Private Helpers ────────────────────────────────────────────────────────

    private static function computeAscii(string $value, string $language = 'en'): string
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
            $latinTable = array_diff_key($transliterations['latin'], $transliterations[$language]);
        } else {
            $latinTable = $transliterations['latin'];
        }

        $value = strtr($value, $latinTable);

        return preg_replace('/[^\x20-\x7E]/u', '', $value) ?? $value;
    }

    private static function computeStudly(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));

        return str_replace(' ', '', $value);
    }

    private static function computeSnake(string $value, string $delimiter = '_'): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        if (preg_match('/[A-Z]/u', $value)) {
            $value = preg_replace('/(.)(?=[A-Z])/u', '$1 ', $value);
            $value = preg_replace('/\s+/u', ' ', $value);
        }

        $value = str_replace(' ', $delimiter, $value);

        return mb_strtolower($value, 'UTF-8');
    }

    private static function studlyToCamel(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return mb_strtolower(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    protected function withValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }
}
