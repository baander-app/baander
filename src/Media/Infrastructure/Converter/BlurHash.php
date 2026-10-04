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
     * Encode an image to a BlurHash string using 1–9 components per axis.
     */
    public static function encode(\GdImage $image, int $componentsX = 4, int $componentsY = 3): string
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $index = imagecolorat($image, $x, $y);
                if ($index === false) {
                    throw new \RuntimeException('Failed to read image pixel.');
                }
                $color = imagecolorsforindex($image, $index);
                $row[] = [
                    'r' => $color['red'] / 255.0,
                    'g' => $color['green'] / 255.0,
                    'b' => $color['blue'] / 255.0,
                ];
            }
            $pixels[] = $row;
        }

        return self::encodePixels($pixels, $width, $height, $componentsX, $componentsY);
    }

    /**
     * Encode from normalized sRGB pixel data (channels between zero and one).
     *
     * @param array<int, array<int, array{r: float, g: float, b: float}>> $pixels
     */
    public static function encodePixels(array $pixels, int $width, int $height, int $componentsX, int $componentsY): string
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Image dimensions must be positive.');
        }
        if ($componentsX < 1 || $componentsX > 9 || $componentsY < 1 || $componentsY > 9) {
            throw new \InvalidArgumentException('BlurHash must have between 1 and 9 components per axis.');
        }
        if (!array_is_list($pixels) || count($pixels) !== $height) {
            throw new \InvalidArgumentException('Pixel rows must match image height.');
        }
        foreach ($pixels as $row) {
            if (!array_is_list($row) || count($row) !== $width) {
                throw new \InvalidArgumentException('Pixel columns must match image width.');
            }
            foreach ($row as $pixel) {
                foreach (['r', 'g', 'b'] as $channel) {
                    if (!is_finite($pixel[$channel]) || $pixel[$channel] < 0 || $pixel[$channel] > 1) {
                        throw new \InvalidArgumentException('Pixel channels must be finite normalized sRGB values.');
                    }
                }
            }
        }

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

        $maximumValue = ($quantizedMaximumValue + 1) / 166.0;

        $dcValue = self::encodeDc($dc['r'], $dc['g'], $dc['b']);

        $result = self::base83Encode($sizeFlag, 1);

        $result .= self::base83Encode($quantizedMaximumValue, 1);

        $result .= self::base83Encode($dcValue, 4);

        foreach ($ac as $factor) {
            $result .= self::encodeAc($factor['r'], $factor['g'], $factor['b'], $maximumValue);
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
        if ($width < 1 || $height < 1 || !is_finite($punch) || $punch < 0) {
            throw new \InvalidArgumentException('Dimensions must be positive and punch must be finite and non-negative.');
        }
        if (strlen($blurHash) < 6) {
            throw new \InvalidArgumentException('BlurHash string is too short.');
        }

        $sizeFlag = self::base83Decode($blurHash, 0, 1);
        $quantizedMaximumValue = self::base83Decode($blurHash, 1, 1);

        $maximumValue = (float) ($quantizedMaximumValue + 1) / 166.0;

        $componentsX = ($sizeFlag % 9) + 1;
        $componentsY = intdiv($sizeFlag, 9) + 1;

        if ($componentsY > 9 || strlen($blurHash) !== 4 + 2 * $componentsX * $componentsY) {
            throw new \InvalidArgumentException('BlurHash length does not match its components.');
        }

        $dc = self::decodeDc(self::base83Decode($blurHash, 2, 4));

        $ac = [];
        for ($i = 0; $i < $componentsX * $componentsY - 1; $i++) {
            $ac[] = self::decodeAc(self::base83Decode($blurHash, 6 + $i * 2, 2), $maximumValue * $punch);
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
                    'r' => self::linearToSrgb($r),
                    'g' => self::linearToSrgb($g),
                    'b' => self::linearToSrgb($b),
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

    private const string BASE83_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';

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
            $digit = strpos(self::BASE83_CHARS, $char);
            if ($digit === false) {
                throw new \InvalidArgumentException('BlurHash contains an invalid base83 character.');
            }
            $value = $value * 83 + $digit;
        }

        return $value;
    }

    private static function encodeDc(float $r, float $g, float $b): int
    {
        $roundedR = self::linearToSrgb($r);
        $roundedG = self::linearToSrgb($g);
        $roundedB = self::linearToSrgb($b);

        return ($roundedR << 16) | ($roundedG << 8) | $roundedB;
    }

    /** @return array{r: float, g: float, b: float} */
    private static function decodeDc(int $value): array
    {
        return [
            'r' => self::srgbToLinear((($value >> 16) & 0xFF) / 255.0),
            'g' => self::srgbToLinear((($value >> 8) & 0xFF) / 255.0),
            'b' => self::srgbToLinear(($value & 0xFF) / 255.0),
        ];
    }

    /** @param list<array{r: float, g: float, b: float}> $ac */
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

    private static function encodeAc(float $r, float $g, float $b, float $maxAc): string
    {
        $qr = (int) floor(self::signPow($r / $maxAc, 0.5) * 9 + 9.5);
        $qg = (int) floor(self::signPow($g / $maxAc, 0.5) * 9 + 9.5);
        $qb = (int) floor(self::signPow($b / $maxAc, 0.5) * 9 + 9.5);

        $qr = max(0, min(18, $qr));
        $qg = max(0, min(18, $qg));
        $qb = max(0, min(18, $qb));

        return self::base83Encode($qr * 19 * 19 + $qg * 19 + $qb, 2);
    }

    /** @return array{r: float, g: float, b: float} */
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

    private static function srgbToLinear(float $value): float
    {
        return $value <= 0.04045 ? $value / 12.92 : pow(($value + 0.055) / 1.055, 2.4);
    }

    private static function linearToSrgb(float $value): int
    {
        $value = max(0.0, min(1.0, $value));
        $srgb = $value <= 0.0031308 ? $value * 12.92 : 1.055 * pow($value, 1 / 2.4) - 0.055;

        return (int) floor($srgb * 255 + 0.5);
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
     * @return array{r: float, g: float, b: float}
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
                $r += self::srgbToLinear($pixel['r']) * $basis;
                $g += self::srgbToLinear($pixel['g']) * $basis;
                $b += self::srgbToLinear($pixel['b']) * $basis;
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
