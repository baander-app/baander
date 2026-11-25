<?php

declare(strict_types=1);

namespace Baander\ImageProcessing\Tests\BlurHash;

use Baander\ImageProcessing\BlurHash\BlurHashGenerator;
use Baander\ImageProcessing\Tests\GdImageConverterTest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BlurHashGeneratorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/blurhash_generator_' . (random_bytes(6) |> bin2hex(...));
        $this->assertTrue(mkdir($this->tmpDir, 0o755, true));
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), (array) glob($this->tmpDir . '/*'));
        @rmdir($this->tmpDir);
    }

    /**
     * The encoder keeps every pixel it reads as a PHP array, about 390 bytes each, so a
     * 2000 by 2000 cover read whole would take 1.5 GB. It reads a small copy instead.
     */
    public function testALargeCoverIsHashedWithinASmallMemoryBudget(): void
    {
        $source = $this->tmpDir . '/cover.png';
        $image = imagecreatetruecolor(2000, 2000);
        $this->assertNotFalse($image);
        foreach (range(0, 1999, 250) as $offset) {
            imagefilledrectangle($image, $offset, 0, $offset + 124, 1999, (int) imagecolorallocate($image, $offset % 256, 90, 200));
        }
        $this->assertTrue(imagepng($image, $source));
        unset($image);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $generator = new BlurHashGenerator($logger);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $hash = $generator->generate($source);
        $peak = memory_get_peak_usage() - $before;

        $this->assertIsString($hash);
        $this->assertSame(28, strlen($hash), 'Four by three components.');
        $this->assertLessThan(48 * 1024 * 1024, $peak, sprintf('Peak use was %d bytes.', $peak));
    }

    public function testAnImageDeclaringMoreThanFiftyMegapixelsGetsNoHashAndIsNotDecoded(): void
    {
        $source = $this->tmpDir . '/declared-huge.png';
        file_put_contents($source, GdImageConverterTest::pngDeclaring(30_000, 30_000));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Failed to generate BlurHash',
            $this->callback(static fn (array $context): bool => str_contains($context['error'] ?? '', '30000 by 30000')),
        );

        $this->assertNull(new BlurHashGenerator($logger)->generate($source));
    }
}
