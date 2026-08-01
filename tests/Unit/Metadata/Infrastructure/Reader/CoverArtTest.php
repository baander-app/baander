<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Infrastructure\Reader;

use App\Metadata\Domain\Model\CoverArt;
use PHPUnit\Framework\TestCase;

final class CoverArtTest extends TestCase
{
    public function testConstructionAndGetters(): void
    {
        $art = new CoverArt(
            type: CoverArt::TYPE_COVER_FRONT,
            mimeType: 'image/jpeg',
            description: 'Front cover',
            imageData: "\xFF\xD8\xFF",
            width: 500,
            height: 500,
            colorDepth: 24,
        );

        $this->assertSame(CoverArt::TYPE_COVER_FRONT, $art->getType());
        $this->assertSame('image/jpeg', $art->getMimeType());
        $this->assertSame('Front cover', $art->getDescription());
        $this->assertSame("\xFF\xD8\xFF", $art->getImageData());
        $this->assertSame(3, $art->getImageSize());
        $this->assertSame(500, $art->getWidth());
        $this->assertSame(500, $art->getHeight());
        $this->assertSame(24, $art->getColorDepth());
    }

    public function testDefaultWidthHeightColorDepthAreZero(): void
    {
        $art = new CoverArt(
            type: CoverArt::TYPE_OTHER,
            mimeType: 'image/png',
            description: '',
            imageData: "\x89PNG",
        );

        $this->assertSame(0, $art->getWidth());
        $this->assertSame(0, $art->getHeight());
        $this->assertSame(0, $art->getColorDepth());
    }

    public function testFromArray(): void
    {
        $art = CoverArt::fromArray([
            'type' => 3,
            'mimeType' => 'image/jpeg',
            'description' => 'Cover',
            'imageData' => 'abc',
            'width' => 200,
            'height' => 200,
            'colorDepth' => 24,
        ]);

        $this->assertSame(3, $art->getType());
        $this->assertSame('image/jpeg', $art->getMimeType());
        $this->assertSame('Cover', $art->getDescription());
        $this->assertSame('abc', $art->getImageData());
        $this->assertSame(200, $art->getWidth());
        $this->assertSame(200, $art->getHeight());
    }

    public function testFromArrayDefaultsMissingDimensions(): void
    {
        $art = CoverArt::fromArray([
            'type' => 0,
            'mimeType' => 'image/gif',
            'description' => '',
            'imageData' => 'GIF89a',
        ]);

        $this->assertSame(0, $art->getWidth());
        $this->assertSame(0, $art->getHeight());
        $this->assertSame(0, $art->getColorDepth());
    }

    public function testIsCoverFrontReturnsTrueForType3(): void
    {
        $art = new CoverArt(
            type: CoverArt::TYPE_COVER_FRONT,
            mimeType: 'image/jpeg',
            description: '',
            imageData: 'x',
        );

        $this->assertTrue($art->isCoverFront());
    }

    public function testIsCoverFrontReturnsFalseForOtherTypes(): void
    {
        $art = new CoverArt(
            type: CoverArt::TYPE_COVER_BACK,
            mimeType: 'image/jpeg',
            description: '',
            imageData: 'x',
        );

        $this->assertFalse($art->isCoverFront());
    }

    public function testGetDataUri(): void
    {
        $art = new CoverArt(
            type: 0,
            mimeType: 'image/png',
            description: '',
            imageData: 'raw-bytes',
        );

        $expected = 'data:image/png;base64,' . base64_encode('raw-bytes');
        $this->assertSame($expected, $art->getDataUri());
    }

    public function testGetTypeNameForKnownTypes(): void
    {
        $art = new CoverArt(
            type: CoverArt::TYPE_COVER_FRONT,
            mimeType: '',
            description: '',
            imageData: '',
        );

        $this->assertSame('Cover (front)', $art->getTypeName());
    }

    public function testGetTypeNameForUnknownType(): void
    {
        $art = new CoverArt(
            type: 99,
            mimeType: '',
            description: '',
            imageData: '',
        );

        $this->assertSame('Unknown (99)', $art->getTypeName());
    }

    public function testImageSizeReturnsByteLength(): void
    {
        $art = new CoverArt(
            type: 0,
            mimeType: '',
            description: '',
            imageData: '12345',
        );

        $this->assertSame(5, $art->getImageSize());
    }

    public function testTypeConstants(): void
    {
        $this->assertSame(0, CoverArt::TYPE_OTHER);
        $this->assertSame(3, CoverArt::TYPE_COVER_FRONT);
        $this->assertSame(4, CoverArt::TYPE_COVER_BACK);
        $this->assertSame(7, CoverArt::TYPE_LEAD_ARTIST);
        $this->assertSame(8, CoverArt::TYPE_ARTIST);
        $this->assertSame(20, CoverArt::TYPE_PUBLISHER_LOGO);
    }
}
