<?php

declare(strict_types=1);

namespace Baander\Tooling\Inventory;

final class Json
{
    public static function canonical(mixed $value): string
    {
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return self::encode($value) . "\n";
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }

    private static function encode(mixed $value): string
    {
        if (is_array($value) || $value instanceof \stdClass) {
            $object = $value instanceof \stdClass || !array_is_list($value);
            $items = (array) $value;
            if ($object) {
                ksort($items, SORT_STRING);
            }
            $parts = [];
            foreach ($items as $key => $item) {
                $parts[] = ($object ? self::encode((string) $key) . ':' : '') . self::encode($item);
            }
            return ($object ? '{' : '[') . implode(',', $parts) . ($object ? '}' : ']');
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if (is_float($value) && abs($value) >= 1.0e16 && !str_contains($encoded, 'e')) {
            $negative = str_starts_with($encoded, '-');
            $digits = explode('.', ltrim($encoded, '-'), 2)[0];
            $tail = rtrim(substr($digits, 1), '0');
            $encoded = ($negative ? '-' : '') . $digits[0] . ($tail === '' ? '' : '.' . $tail) . 'e+' . (strlen($digits) - 1);
        }
        if (is_float($value) && str_contains($encoded, 'e')) {
            [$mantissa, $power] = explode('e', $encoded);
            $exponent = (int) $power;
            if ($exponent >= -4 && $exponent < 16) {
                $negative = str_starts_with($mantissa, '-');
                $digits = str_replace(['-', '.'], '', $mantissa);
                $point = 1 + $exponent;
                $encoded = ($negative ? '-' : '') . ($point <= 0
                    ? '0.' . str_repeat('0', -$point) . $digits
                    : substr(str_pad($digits, $point, '0'), 0, $point) . '.' . substr($digits, $point));
                $encoded = $encoded
                    |> (static fn(string $number): string => rtrim($number, '0'))
                    |> (static fn(string $number): string => rtrim($number, '.'));
                if (!str_contains($encoded, '.')) {
                    $encoded .= '.0';
                }
            } else {
                $encoded = preg_replace('/\.0$/', '', $mantissa) . 'e' . ($exponent < 0 ? '-' : '+') . str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
            }
        }
        return $encoded;
    }

    public static function read(string $path): mixed
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Cannot read JSON: ' . $path);
        }
        return $contents
            |> (static fn(string $json): mixed => json_decode($json, false, flags: JSON_THROW_ON_ERROR))
            |> self::arrays(...);
    }

    private static function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            if (get_object_vars($value) === []) {
                return $value;
            }
            $members = $value
                |> get_object_vars(...)
                |> (static fn(array $members): array => array_map(self::arrays(...), $members));
            return array_is_list($members) ? (object) $members : $members;
        }
        return is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }
}
