<?php

namespace App\Format;

/**
 * Format byte counts into human-readable units (KB, MB, GB, etc.).
 *
 * @example
 * Bytes::format(1024) // "1 KB"
 * Bytes::format(1536, precision: 0) // "1 KB"
 * Bytes::format(1536, precision: 2) // "1.50 KB"
 */
class Bytes
{
    private const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

    /**
     * Format bytes into human-readable format.
     *
     * @param int|float $bytes The number of bytes
     * @param int $precision Number of decimal places
     * @param string $separator Separator between number and unit
     * @return string Formatted string (e.g., "1.5 KB")
     */
    public static function format(int|float $bytes, int $precision = 2, string $separator = ' '): string
    {
        if ($bytes < 0) {
            $bytes = abs($bytes);
        }

        if ($bytes < 1024) {
            return $bytes . $separator . 'B';
        }

        $factor = (int)floor(log($bytes, 1024));
        $unit = self::UNITS[$factor] ?? self::UNITS[count(self::UNITS) - 1];

        return sprintf(
            "%.{$precision}f",
            $bytes / (1024 ** $factor)
        ) . $separator . $unit;
    }

    /**
     * Format bytes with binary units (KiB, MiB, etc.).
     */
    public static function formatBinary(int|float $bytes, int $precision = 2, string $separator = ' '): string
    {
        if ($bytes < 0) {
            $bytes = abs($bytes);
        }

        if ($bytes < 1024) {
            return $bytes . $separator . 'B';
        }

        $factor = (int)floor(log($bytes, 1024));
        $unit = self::UNITS[$factor] ?? self::UNITS[count(self::UNITS) - 1];
        $binaryUnit = str_replace('B', 'iB', $unit);

        return sprintf(
            "%.{$precision}f",
            $bytes / (1024 ** $factor)
        ) . $separator . $binaryUnit;
    }

    /**
     * Parse a human-readable byte string back to bytes.
     *
     * @example Bytes::parse("1.5 KB") // 1536
     * @example Bytes::parse("10 MB") // 10485760
     */
    public static function parse(string $formatted): int|float
    {
        $formatted = trim($formatted);
        $formatted = str_ireplace(['iB', 'ib'], 'B', $formatted);

        preg_match('/^([0-9.]+)\s*([A-Z]+)$/i', $formatted, $matches);

        if (empty($matches)) {
            return 0;
        }

        [, $number, $unit] = $matches;
        $number = (float)$number;
        $unit = strtoupper($unit);

        $factor = array_search($unit, self::UNITS, true);

        if ($factor === false) {
            return 0;
        }

        return $number * (1024 ** $factor);
    }

    /**
     * Get the appropriate unit for a given byte count.
     */
    public static function getUnit(int|float $bytes): string
    {
        if ($bytes < 1024) {
            return 'B';
        }

        $factor = (int)floor(log($bytes, 1024));

        return self::UNITS[$factor] ?? self::UNITS[count(self::UNITS) - 1];
    }

    /**
     * Convert bytes to a specific unit.
     */
    public static function toUnit(int|float $bytes, string $unit, int $precision = 2): float
    {
        $unit = strtoupper(str_replace('iB', 'B', $unit));
        $factor = array_search($unit, self::UNITS, true);

        if ($factor === false) {
            throw new \InvalidArgumentException("Invalid unit: {$unit}");
        }

        return round($bytes / (1024 ** $factor), $precision);
    }

    /**
     * Convert from one unit to another.
     */
    public static function convert(float $value, string $fromUnit, string $toUnit, int $precision = 2): float
    {
        $fromUnit = strtoupper(str_replace('iB', 'B', $fromUnit));
        $toUnit = strtoupper(str_replace('iB', 'B', $toUnit));

        $fromFactor = array_search($fromUnit, self::UNITS, true);
        $toFactor = array_search($toUnit, self::UNITS, true);

        if ($fromFactor === false || $toFactor === false) {
            throw new \InvalidArgumentException("Invalid unit provided");
        }

        $bytes = $value * (1024 ** $fromFactor);

        return round($bytes / (1024 ** $toFactor), $precision);
    }

    /**
     * Format file size with automatic precision (less precision for larger units).
     */
    public static function formatAuto(int|float $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $factor = (int)floor(log($bytes, 1024));

        // Auto-adjust precision based on unit size
        $precision = match ($factor) {
            1 => 0, // KB
            2 => 1, // MB
            default => 2, // GB and above
        };

        return self::format($bytes, $precision);
    }
}
