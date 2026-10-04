<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Infrastructure\Converter;

use App\Media\Infrastructure\Converter\BlurHash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlurHashTest extends TestCase
{
    /**
     * Fixed vectors generated independently with the installed Wolt blurhash 2.0.5
     * package. Pixel channels here are normalized sRGB, not linear RGB.
     *
     * @return iterable<string, array{list<list<array{r: float, g: float, b: float}>>, int, int, int, int, string}>
     */
    public static function encodingVectors(): iterable
    {
        yield 'gray DC' => [[[[ 'r' => 128 / 255, 'g' => 128 / 255, 'b' => 128 / 255 ]]], 1, 1, 1, 1, '00Eyb['];
        yield 'mixed colors' => [self::pixels([[32, 64, 96], [200, 150, 100], [250, 30, 80], [10, 240, 160]], 2), 2, 2, 2, 2, 'A|JHQw||=|kD'];
        yield 'primary colors' => [self::pixels([[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 255]], 2), 2, 2, 2, 2, 'A~Lqe9|l~h|c'];
        yield 'zero quantized AC maximum' => [self::pixels([[0, 0, 0], [1, 1, 1]], 2), 2, 1, 2, 1, '1009jvfQ'];
    }

    /** @param list<list<array{r: float, g: float, b: float}>> $pixels */
    #[DataProvider('encodingVectors')]
    public function testEncodesReferenceVectors(array $pixels, int $width, int $height, int $componentsX, int $componentsY, string $hash): void
    {
        $this->assertSame($hash, BlurHash::encodePixels($pixels, $width, $height, $componentsX, $componentsY));
    }

    public function testDecodesReferenceImage(): void
    {
        $this->assertSame([
            [['r' => 135, 'g' => 164, 'b' => 177], ['r' => 172, 'g' => 177, 'b' => 175], ['r' => 170, 'g' => 175, 'b' => 172]],
            [['r' => 120, 'g' => 148, 'b' => 162], ['r' => 150, 'g' => 128, 'b' => 125], ['r' => 150, 'g' => 135, 'b' => 125]],
        ], BlurHash::decode('LEHV6nWB2yk8pyo0adR*.7kCMdnj', 3, 2));
    }

    public function testPunchScalesOnlyAcComponents(): void
    {
        $this->assertSame([
            [['r' => 91, 'g' => 187, 'b' => 221], ['r' => 207, 'g' => 219, 'b' => 216], ['r' => 202, 'g' => 215, 'b' => 208]],
            [['r' => 0, 'g' => 143, 'b' => 184], ['r' => 149, 'g' => 52, 'b' => 24], ['r' => 148, 'g' => 95, 'b' => 34]],
        ], BlurHash::decode('LEHV6nWB2yk8pyo0adR*.7kCMdnj', 3, 2, 3));
        $this->assertSame([[['r' => 128, 'g' => 128, 'b' => 128]]], BlurHash::decode('00Eyb[', 1, 1, 3));
    }

    public function testGdTrueColorAndPaletteProduceSameReferenceHash(): void
    {
        foreach ([imagecreatetruecolor(2, 2), imagecreate(2, 2)] as $image) {
            self::assertInstanceOf(\GdImage::class, $image);
            foreach ([[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 255]] as $index => [$r, $g, $b]) {
                $color = imagecolorallocate($image, $r, $g, $b);
                self::assertNotFalse($color);
                imagesetpixel($image, $index % 2, intdiv($index, 2), $color);
            }
            $this->assertSame('A~Lqe9|l~h|c', BlurHash::encode($image, 2, 2));
        }
        $decoded = BlurHash::decodeToGdImage('00Eyb[', 2, 1);
        $this->assertSame(0x808080, imagecolorat($decoded, 0, 0));
        $this->assertSame(0x808080, imagecolorat($decoded, 1, 0));
    }

    /** @return iterable<string, array{string, int, int, float}> */
    public static function invalidHashes(): iterable
    {
        yield 'short' => ['00000', 1, 1, 1.0];
        yield 'truncated AC' => ['100000', 1, 1, 1.0];
        yield 'extra DC bytes' => ['00000000', 1, 1, 1.0];
        yield 'invalid alphabet' => ['00Eyb&', 1, 1, 1.0];
        yield 'invalid size flag' => ['~00000', 1, 1, 1.0];
        yield 'zero width' => ['00Eyb[', 0, 1, 1.0];
        yield 'negative height' => ['00Eyb[', 1, -1, 1.0];
        yield 'nonfinite punch' => ['00Eyb[', 1, 1, INF];
    }

    #[DataProvider('invalidHashes')]
    public function testRejectsInvalidDecodeInputs(string $hash, int $width, int $height, float $punch): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BlurHash::decode($hash, $width, $height, $punch);
    }

    /** @return iterable<string, array{array<int, array<int, array{r: float, g: float, b: float}>>, int, int, int, int}> */
    public static function invalidPixels(): iterable
    {
        yield 'zero dimension' => [[], 0, 1, 1, 1];
        yield 'missing pixel' => [[], 1, 1, 1, 1];
        yield 'extra pixel' => [self::pixels([[0, 0, 0], [0, 0, 0]], 2), 1, 1, 1, 1];
        yield 'invalid component count' => [self::pixels([[0, 0, 0]], 1), 1, 1, 0, 1];
        yield 'too many components' => [self::pixels([[0, 0, 0]], 1), 1, 1, 1, 10];
        yield 'nonfinite channel' => [[[['r' => NAN, 'g' => 0.0, 'b' => 0.0]]], 1, 1, 1, 1];
        yield 'out of range channel' => [[[['r' => 2.0, 'g' => 0.0, 'b' => 0.0]]], 1, 1, 1, 1];
    }

    /** @param array<int, array<int, array{r: float, g: float, b: float}>> $pixels */
    #[DataProvider('invalidPixels')]
    public function testRejectsInvalidEncodeInputs(array $pixels, int $width, int $height, int $componentsX, int $componentsY): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BlurHash::encodePixels($pixels, $width, $height, $componentsX, $componentsY);
    }

    /**
     * @param list<array{int, int, int}> $rgb
     * @return list<list<array{r: float, g: float, b: float}>>
     */
    private static function pixels(array $rgb, int $width): array
    {
        return array_chunk(array_map(static fn (array $pixel): array => ['r' => $pixel[0] / 255, 'g' => $pixel[1] / 255, 'b' => $pixel[2] / 255], $rgb), $width);
    }
}
