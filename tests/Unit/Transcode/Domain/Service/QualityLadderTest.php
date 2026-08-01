<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Domain\Service;

use App\Transcode\Domain\Service\QualityLadder;
use App\Transcode\Domain\ValueObject\QualityTier;
use PHPUnit\Framework\TestCase;

class QualityLadderTest extends TestCase
{
    public function testDefaultTiersReturnsSixTiers(): void
    {
        $tiers = QualityLadder::defaultTiers();

        $this->assertCount(6, $tiers);
        $this->assertSame('360p', $tiers[0]->name);
        $this->assertSame('4K', $tiers[5]->name);
    }

    public function testDefaultTiersAreSortedByResolution(): void
    {
        $tiers = QualityLadder::defaultTiers();

        for ($i = 1; $i < count($tiers); $i++) {
            $this->assertGreaterThan(
                $tiers[$i - 1]->height,
                $tiers[$i]->height,
                "Tier at index {$i} should have higher resolution than previous",
            );
        }
    }

    public function testTierForResolutionReturnsClosestMatch(): void
    {
        $tier = QualityLadder::tierForResolution(1080);
        $this->assertNotNull($tier);
        $this->assertSame(1080, $tier->height);
    }

    public function testTierForResolutionWithOddValue(): void
    {
        $tier = QualityLadder::tierForResolution(900);
        $this->assertNotNull($tier);
        // 900 is closest to 720 (diff 180) vs 1080 (diff 180) — 720 wins by order
        $this->assertSame(720, $tier->height);
    }

    public function testTierForResolutionWithVeryLowValue(): void
    {
        $tier = QualityLadder::tierForResolution(100);
        $this->assertNotNull($tier);
        $this->assertSame('360p', $tier->name);
    }

    public function testTierForResolutionWithVeryHighValue(): void
    {
        $tier = QualityLadder::tierForResolution(4000);
        $this->assertNotNull($tier);
        $this->assertSame('4K', $tier->name);
    }

    public function testRfc6381CodecString(): void
    {
        $tier = QualityTier::p1080();
        $codecString = QualityLadder::rfc6381CodecString($tier);

        $this->assertStringContainsString($tier->rfc6381Codec, $codecString);
        $this->assertStringContainsString('mp4a.40.2', $codecString);
    }

    public function testRfc6381CodecStringWithCustomAudio(): void
    {
        $tier = QualityTier::p720();
        $codecString = QualityLadder::rfc6381CodecString($tier, 'opus');

        $this->assertStringContainsString($tier->rfc6381Codec, $codecString);
        $this->assertStringContainsString('opus', $codecString);
    }
}
