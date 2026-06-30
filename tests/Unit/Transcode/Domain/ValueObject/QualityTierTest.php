<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\ValueObject;

use App\Transcode\Domain\ValueObject\QualityTier;
use PHPUnit\Framework\TestCase;

class QualityTierTest extends TestCase
{
    public function testFactoryMethodsReturnCorrectDimensionsAndBitrate(): void
    {
        $p360 = QualityTier::p360();
        $this->assertSame('360p', $p360->name);
        $this->assertSame(360, $p360->height);
        $this->assertSame(640, $p360->width);
        $this->assertSame(800_000, $p360->videoBitrate);

        $p1080 = QualityTier::p1080();
        $this->assertSame('1080p', $p1080->name);
        $this->assertSame(1080, $p1080->height);
        $this->assertSame(1920, $p1080->width);
        $this->assertSame(5_000_000, $p1080->videoBitrate);

        $p4K = QualityTier::p4K();
        $this->assertSame('4K', $p4K->name);
        $this->assertSame(2160, $p4K->height);
        $this->assertSame(3840, $p4K->width);
        $this->assertSame(20_000_000, $p4K->videoBitrate);
    }

    public function testAllTiersUseHvc1Codec(): void
    {
        $tiers = [
            QualityTier::p360(),
            QualityTier::p480(),
            QualityTier::p720(),
            QualityTier::p1080(),
            QualityTier::p1440(),
            QualityTier::p4K(),
        ];

        foreach ($tiers as $tier) {
            $this->assertSame('hvc1', $tier->codec, "Tier {$tier->name} should use hvc1 codec");
            $this->assertNotEmpty($tier->rfc6381Codec, "Tier {$tier->name} should have an RFC 6381 codec string");
            $this->assertGreaterThan($tier->videoBitrate, $tier->maxBitrate, "Tier {$tier->name} maxBitrate should exceed videoBitrate");
            $this->assertGreaterThan($tier->maxBitrate, $tier->bufferSize, "Tier {$tier->name} bufferSize should exceed maxBitrate");
        }
    }

    public function testFromStringReturnsCorrectTier(): void
    {
        $this->assertEquals(QualityTier::p360(), QualityTier::fromString('360p'));
        $this->assertEquals(QualityTier::p720(), QualityTier::fromString('720p'));
        $this->assertEquals(QualityTier::p1080(), QualityTier::fromString('1080p'));
        $this->assertEquals(QualityTier::p4K(), QualityTier::fromString('4K'));
        $this->assertEquals(QualityTier::p4K(), QualityTier::fromString('2160p'));
    }

    public function testFromStringWithInvalidTierThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown quality tier: "8k"');

        QualityTier::fromString('8k');
    }

    public function testEquals(): void
    {
        $this->assertTrue(QualityTier::p1080()->equals(QualityTier::p1080()));
        $this->assertFalse(QualityTier::p1080()->equals(QualityTier::p720()));
    }

    public function testJsonSerialize(): void
    {
        $tier = QualityTier::p720();
        $serialized = $tier->jsonSerialize();

        $this->assertSame('720p', $serialized['name']);
        $this->assertSame(720, $serialized['height']);
        $this->assertSame(1280, $serialized['width']);
        $this->assertArrayHasKey('rfc6381Codec', $serialized);
    }

    public function testPerTierRfc6381CodecStringsAreDistinct(): void
    {
        $tiers = [
            'p360'  => QualityTier::p360(),
            'p480'  => QualityTier::p480(),
            'p720'  => QualityTier::p720(),
            'p1080' => QualityTier::p1080(),
            'p1440' => QualityTier::p1440(),
            'p4K'   => QualityTier::p4K(),
        ];

        // Verify expected codec strings for each tier
        $this->assertSame('hvc1.1.6.L93.B0', $tiers['p360']->rfc6381Codec);
        $this->assertSame('hvc1.1.6.L93.B0', $tiers['p480']->rfc6381Codec);
        $this->assertSame('hvc1.1.6.L93.B0', $tiers['p720']->rfc6381Codec);
        $this->assertSame('hvc1.1.6.L120.B0', $tiers['p1080']->rfc6381Codec);
        $this->assertSame('hvc1.1.6.L150.B0', $tiers['p1440']->rfc6381Codec);
        $this->assertSame('hvc1.1.6.L186.B0', $tiers['p4K']->rfc6381Codec);
    }

    public function testRfc6381CodecIncreasesWithResolution(): void
    {
        // Higher tiers should have >= Level of lower tiers.
        // Extract the numeric level (e.g. L93 → 93) for correct numeric comparison,
        // since lexicographic comparison fails for L93 vs L120.
        $extractLevel = static fn(string $codec): int => (int) preg_replace('/.*L(\d+).*/', '$1', $codec);

        $this->assertLessThan(
            $extractLevel(QualityTier::p1080()->rfc6381Codec),
            $extractLevel(QualityTier::p720()->rfc6381Codec),
        );
        $this->assertLessThan(
            $extractLevel(QualityTier::p1440()->rfc6381Codec),
            $extractLevel(QualityTier::p1080()->rfc6381Codec),
        );
        $this->assertLessThan(
            $extractLevel(QualityTier::p4K()->rfc6381Codec),
            $extractLevel(QualityTier::p1440()->rfc6381Codec),
        );
    }
}
