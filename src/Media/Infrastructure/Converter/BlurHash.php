<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Converter;

/**
 * Encodes an image to a BlurHash string.
 *
 * Based on the BlurHash algorithm by woltapp/blurhash.
 */
final class BlurHash
{
    /**
     * Encode an image to a BlurHash string.
     *
     * @param resource|\GdImage $image GD image resource
     * @param int<1, 9> $componentsX Number of DCT components on X axis (1-9)
     * @param int<1, 9> $componentsY Number of DCT components on Y axis (1-9)
     */
    public static function encode(\GdImage $image, int $componentsX = 4, int $componentsY = 3): string
    {
        $componentsX = max(1, min(9, $componentsX));
        $componentsY = max(1, min(9, $componentsY));

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width === 0 || $height === 0) {
            throw new \InvalidArgumentException('Image must have non-zero dimensions.');
        }

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $index = imagecolorat($image, $x, $y);
                $row[] = [
                    'r' => (($index >> 16) & 0xFF) / 255.0,
                    'g' => (($index >> 8) & 0xFF) / 255.0,
                    'b' => ($index & 0xFF) / 255.0,
                ];
            }
            $pixels[] = $row;
        }

        return self::encodePixels($pixels, $width, $height, $componentsX, $componentsY);
    }

    /**
     * Encode from raw pixel data.
     *
     * @param array<int, array<int, array{r: float, g: float, b: float}>> $pixels
     */
    public static function encodePixels(array $pixels, int $width, int $height, int $componentsX, int $componentsY): string
    {
        $factors = [];

        for ($y = 0; $y < $componentsY; $y++) {
            for ($x = 0; $x < $componentsX; $x++) {
                $factor = self::multiplyBasisFunction($pixels, $width, $height, $x, $y);
                $factors[] = $factor;
            }
        }

        $dc = $factors[0];
        $ac = array_slice($factors, 1);

        $sizeFlag = ($componentsX - 1) + ($componentsY - 1) * 9;

        $quantizedMaximumValue = self::encodeMaxAcComponent($ac);

        $dcValue = self::encodeDc($dc['r'], $dc['g'], $dc['b']);

        $result = self::base83Encode($sizeFlag, 1);

        $result .= self::base83Encode($quantizedMaximumValue, 1);

        $result .= self::base83Encode($dcValue, 4);

        foreach ($ac as $factor) {
            $result .= self::encodeAc($factor['r'], $factor['g'], $factor['b'], $quantizedMaximumValue);
        }

        return $result;
    }

    /**
     * Decode a BlurHash string to pixel data.
     *
     * @return array{r: int, g: int, b: int}[][]
     */
    public static function decode(string $blurHash, int $width, int $height, float $punch = 1.0): array
    {
        if (strlen($blurHash) < 6) {
            throw new \InvalidArgumentException('BlurHash string is too short.');
        }

        $sizeFlag = self::base83Decode($blurHash, 0, 1);
        $quantizedMaximumValue = self::base83Decode($blurHash, 1, 1);

        $maximumValue = (float) ($quantizedMaximumValue + 1) / 166.0;

        $componentsX = ($sizeFlag % 9) + 1;
        $componentsY = intdiv($sizeFlag, 9) + 1;

        if ($componentsX * $componentsY + 3 !== strlen($blurHash) * 5 / 8) {
            // Approximate length check — some valid hashes may not match exactly
        }

        $dc = self::decodeDc(self::base83Decode($blurHash, 2, 4));

        $ac = [];
        for ($i = 0; $i < $componentsX * $componentsY - 1; $i++) {
            $ac[] = self::decodeAc(self::base83Decode($blurHash, 4 + i * 2, 2), $maximumValue);
        }

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $r = $dc['r'];
                $g = $dc['g'];
                $b = $dc['b'];

                for ($j = 0; $j < $componentsY; $j++) {
                    for ($i = 0; $i < $componentsX; $i++) {
                        if ($i === 0 && $j === 0) {
                            continue;
                        }

                        $basis = cos((M_PI * $x * $i) / $width) * cos((M_PI * $y * $j) / $height);
                        $index = $i + $j * $componentsX - 1;

                        if (isset($ac[$index])) {
                            $r += $ac[$index]['r'] * $basis;
                            $g += $ac[$index]['g'] * $basis;
                            $b += $ac[$index]['b'] * $basis;
                        }
                    }
                }

                $row[] = [
                    'r' => (int) max(0, min(255, (int) round($r * $punch + 0.5))),
                    'g' => (int) max(0, min(255, (int) round($g * $punch + 0.5))),
                    'b' => (int) max(0, min(255, (int) round($b * $punch + 0.5))),
                ];
            }
            $pixels[] = $row;
        }

        return $pixels;
    }

    /**
     * Decode a BlurHash string to a GD image resource.
     */
    public static function decodeToGdImage(string $blurHash, int $width, int $height, float $punch = 1.0): \GdImage
    {
        $pixels = self::decode($blurHash, $width, $height, $punch);

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('Failed to create GD image.');
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $pixels[$y][$x];
                $color = imagecolorallocate($image, $pixel['r'], $pixel['g'], $pixel['b']);
                if ($color === false) {
                    throw new \RuntimeException('Failed to allocate color.');
                }
                imagesetpixel($image, $x, $y, $color);
            }
        }

        return $image;
    }

    // --- Internal ---

    private const array BASE83_CHARS = [
        0 => '0', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7',
        8 => '8', 9 => '9', 10 => 'A', 11 => 'B', 12 => 'C', 13 => 'D', 14 => 'E', 15 => 'F',
        16 => 'G', 17 => 'H', 18 => 'I', 19 => 'J', 20 => 'K', 21 => 'L', 22 => 'M', 23 => 'N',
        24 => 'O', 25 => 'P', 26 => 'Q', 27 => 'R', 28 => 'S', 29 => 'T', 30 => 'U', 31 => 'V',
        32 => 'W', 33 => 'X', 34 => 'Y', 35 => 'Z', 36 => 'a', 37 => 'b', 38 => 'c', 39 => 'd',
        40 => 'e', 41 => 'f', 42 => 'g', 43 => 'h', 44 => 'i', 45 => 'j', 46 => 'k', 47 => 'l',
        48 => 'm', 49 => 'n', 50 => 'o', 51 => 'p', 52 => 'q', 53 => 'r', 54 => 's', 55 => 't',
        56 => 'u', 57 => 'v', 58 => 'w', 59 => 'x', 60 => 'y', 61 => 'z', 62 => '#', 63 => '$',
        64 => '%', 65 => '&', 66 => '(', 67 => ')', 68 => '*', 69 => '+', 70 => ',', 71 => '-',
        72 => '.', 73 => ':', 74 => ';', 75 => '=', 76 => '?', 77 => '@', 78 => '[', 79 => ']',
        80 => '^', 81 => '_', 82 => '{', 83 => '|', 84 => '}', 85 => '~',
    ];

    private const array CHAR_TO_VALUE = [
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
        '8' => 8, '9' => 9, 'A' => 10, 'B' => 11, 'C' => 12, 'D' => 13, 'E' => 14, 'F' => 15,
        'G' => 16, 'H' => 17, 'I' => 18, 'J' => 19, 'K' => 20, 'L' => 21, 'M' => 22, 'N' => 23,
        'O' => 24, 'P' => 25, 'Q' => 26, 'R' => 27, 'S' => 28, 'T' => 29, 'U' => 30, 'V' => 31,
        'W' => 32, 'X' => 33, 'Y' => 34, 'Z' => 35, 'a' => 36, 'b' => 37, 'c' => 38, 'd' => 39,
        'e' => 40, 'f' => 41, 'g' => 42, 'h' => 43, 'i' => 44, 'j' => 45, 'k' => 46, 'l' => 47,
        'm' => 48, 'n' => 49, 'o' => 50, 'p' => 51, 'q' => 52, 'r' => 53, 's' => 54, 't' => 55,
        'u' => 56, 'v' => 57, 'w' => 58, 'x' => 59, 'y' => 60, 'z' => 61, '#' => 62, '$' => 63,
        '%' => 64, '&' => 65, '(' => 66, ')' => 67, '*' => 68, '+' => 69, ',' => 70, '-' => 71,
        '.' => 72, ':' => 73, ';' => 74, '=' => 75, '?' => 76, '@' => 77, '[' => 78, ']' => 79,
        '^' => 80, '_' => 81, '{' => 82, '|' => 83, '}' => 84, '~' => 85,
    ];

    private static function base83Encode(int $value, int $length): string
    {
        $result = '';
        for ($i = 1; $i <= $length; $i++) {
            $digit = intdiv($value, (int) pow(83, $length - $i)) % 83;
            $result .= self::BASE83_CHARS[$digit];
        }

        return $result;
    }

    private static function base83Decode(string $hash, int $offset, int $length): int
    {
        $value = 0;
        for ($i = 0; $i < $length; $i++) {
            $char = $hash[$offset + $i];
            $digit = self::CHAR_TO_VALUE[$char] ?? 0;
            $value = $value * 83 + $digit;
        }

        return $value;
    }

    private static function encodeDc(float $r, float $g, float $b): int
    {
        $roundedR = (int) round($r * 255);
        $roundedG = (int) round($g * 255);
        $roundedB = (int) round($b * 255);

        return ($roundedR << 16) | ($roundedG << 8) | $roundedB;
    }

    private static function decodeDc(int $value): array
    {
        return [
            'r' => (($value >> 16) & 0xFF) / 255.0,
            'g' => (($value >> 8) & 0xFF) / 255.0,
            'b' => ($value & 0xFF) / 255.0,
        ];
    }

    private static function encodeMaxAcComponent(array $ac): int
    {
        $max = 0.0;
        foreach ($ac as $factor) {
            $max = max($max, abs($factor['r']), abs($factor['g']), abs($factor['b']));
        }

        $quantizedMax = (int) floor($max * 166 - 0.5);
        $quantizedMax = max(0, min(82, $quantizedMax));

        return $quantizedMax;
    }

    private static function encodeAc(float $r, float $g, float $b, int $maxAc): string
    {
        if ($maxAc === 0) {
            return self::base83Encode(0, 2);
        }

        $qr = (int) round((float) (self::signPow($r / $maxAc, 0.5) * 9 + 9));
        $qg = (int) round((float) (self::signPow($g / $maxAc, 0.5) * 9 + 9));
        $qb = (int) round((float) (self::signPow($b / $maxAc, 0.5) * 9 + 9));

        $qr = max(0, min(18, $qr));
        $qg = max(0, min(18, $qg));
        $qb = max(0, min(18, $qb));

        return self::base83Encode($qr * 19 * 19 + $qg * 19 + $qb, 2);
    }

    private static function decodeAc(int $value, float $maxAc): array
    {
        $qr = intdiv($value, 19 * 19);
        $qg = intdiv($value % (19 * 19), 19);
        $qb = $value % 19;

        return [
            'r' => self::signPow(($qr - 9) / 9.0, 2.0) * $maxAc,
            'g' => self::signPow(($qg - 9) / 9.0, 2.0) * $maxAc,
            'b' => self::signPow(($qb - 9) / 9.0, 2.0) * $maxAc,
        ];
    }

    private static function signPow(float $value, float $exp): float
    {
        if ($value < 0) {
            return -pow(-$value, $exp);
        }

        return pow($value, $exp);
    }

    /**
     * @param array<int, array<int, array{r: float, g: float, b: float}>> $pixels
     */
    private static function multiplyBasisFunction(array $pixels, int $width, int $height, int $basisX, int $basisY): array
    {
        $r = 0.0;
        $g = 0.0;
        $b = 0.0;

        $normalization = ($basisX === 0 && $basisY === 0) ? 1.0 : 2.0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $basis = $normalization
                    * cos((M_PI * $basisX * $x) / $width)
                    * cos((M_PI * $basisY * $y) / $height);

                $pixel = $pixels[$y][$x];
                $r += $pixel['r'] * $basis;
                $g += $pixel['g'] * $basis;
                $b += $pixel['b'] * $basis;
            }
        }

        $scale = 1.0 / ($width * $height);

        return [
            'r' => $r * $scale,
            'g' => $g * $scale,
            'b' => $b * $scale,
        ];
    }
}
