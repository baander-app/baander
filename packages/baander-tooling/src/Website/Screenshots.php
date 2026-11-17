<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use RuntimeException;

/**
 * The site's application pictures. A picture named `music` is two files of one view: `music.webp`
 * for the dark appearance and `music-light.webp` for the light one.
 */
final class Screenshots
{
    public const string DIRECTORY = 'ui/site/assets/screenshots';
    private const string LIGHT_SUFFIX = '-light.webp';

    /**
     * @return array<string, array{width: int, height: int}> the dark file's dimensions of every picture that has both files, by name
     */
    public static function pairs(string $root): array
    {
        $pairs = [];
        foreach (glob(rtrim($root, '/') . '/' . self::DIRECTORY . '/*' . self::LIGHT_SUFFIX) ?: [] as $light) {
            $name = basename($light, self::LIGHT_SUFFIX);
            $dark = dirname($light) . '/' . $name . '.webp';
            if (is_file($dark)) {
                $pairs[$name] = self::size($dark) ?? throw new RuntimeException('Cannot read picture dimensions: ' . $dark);
            }
        }

        return $pairs;
    }

    /** @return array{dark: string, light: string} the repository paths of a picture's two files */
    public static function files(string $name): array
    {
        return [
            'dark' => self::DIRECTORY . '/' . $name . '.webp',
            'light' => self::DIRECTORY . '/' . $name . self::LIGHT_SUFFIX,
        ];
    }

    /** @return ?array{width: int, height: int} null when the file is absent or is not a picture */
    public static function size(string $path): ?array
    {
        $size = is_file($path) ? @getimagesize($path) : false;

        return $size === false ? null : ['width' => $size[0], 'height' => $size[1]];
    }
}
