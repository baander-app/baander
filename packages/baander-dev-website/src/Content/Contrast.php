<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Content;

use InvalidArgumentException;

/**
 * WCAG 2 contrast between opaque sRGB colors: the relative luminance of each color, then
 * (lighter + 0.05) / (darker + 0.05). It is the formula the design-token generator checks its
 * themes with, so a ratio the site shows is the ratio the generator accepted.
 */
final class Contrast
{
    /** The ratio between two `#rrggbb` colors, from 1 to 21. */
    public static function ratio(string $first, string $second): float
    {
        $luminances = [self::luminance($first), self::luminance($second)];

        return (max($luminances) + 0.05) / (min($luminances) + 0.05);
    }

    /**
     * The surface a color contrasts least with, and that ratio.
     *
     * @param array<string, string> $surfaces colors by the name the result reports
     * @return array{ratio: float, surface: string}
     */
    public static function lowest(string $color, array $surfaces): array
    {
        if ($surfaces === []) {
            throw new InvalidArgumentException('A lowest contrast needs at least one surface.');
        }
        $ratios = array_map(static fn(string $surface): float => self::ratio($color, $surface), $surfaces);
        $surface = array_keys($ratios, min($ratios), true)[0];

        return ['ratio' => $ratios[$surface], 'surface' => (string) $surface];
    }

    /**
     * A ratio as text with two decimals, truncated rather than rounded, so a ratio just below a
     * target never reads as meeting it. The hundredths are rounded to six places before the floor,
     * because a binary double such as 1.15 scales to 114.999…, which would truncate a decimal down.
     */
    public static function format(float $ratio): string
    {
        return number_format(floor(round($ratio * 100, 6)) / 100, 2, '.', '');
    }

    private static function luminance(string $color): float
    {
        if (preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
            throw new InvalidArgumentException('Contrast needs an opaque #rrggbb color, not ' . $color . '.');
        }
        [$red, $green, $blue] = str_split(substr($color, 1), 2)
            |> (static fn(array $pairs): array => array_map(self::channel(...), $pairs));

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    /** One channel's linear value, from its two hexadecimal digits. */
    private static function channel(string $digits): float
    {
        $value = hexdec($digits) / 255;

        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }
}
