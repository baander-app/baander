<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Tests;

use Baander\DevWebsite\Content\Contrast;
use PHPUnit\Framework\TestCase;

final class ContrastTest extends TestCase
{
    public function testBlackOnWhiteIsTwentyOneToOneInEitherOrder(): void
    {
        self::assertEqualsWithDelta(21.0, Contrast::ratio('#000000', '#ffffff'), 1e-9);
        self::assertEqualsWithDelta(21.0, Contrast::ratio('#FFFFFF', '#000000'), 1e-9);
        self::assertEqualsWithDelta(1.0, Contrast::ratio('#6e5ca5', '#6e5ca5'), 1e-9);
    }

    public function testRatiosMatchTheDesignTokenGenerator(): void
    {
        // Computed with contrast() from ui/design-tokens/web.mjs.
        self::assertEqualsWithDelta(7.716571002745007, Contrast::ratio('#a78bfa', '#000000'), 1e-12);
        self::assertEqualsWithDelta(4.505720081544952, Contrast::ratio('#6e5ca5', '#efe5d5'), 1e-12);
    }

    public function testLowestNamesTheSurfaceWithTheLeastContrast(): void
    {
        self::assertSame(
            ['ratio' => Contrast::ratio('#6e5ca5', '#efe5d5'), 'surface' => 'secondary'],
            Contrast::lowest('#6e5ca5', ['background' => '#faf5ee', 'secondary' => '#efe5d5', 'card' => '#f5ede0']),
        );
    }

    public function testFormatTruncatesSoARatioJustBelowATargetNeverReadsAsMeetingIt(): void
    {
        self::assertSame('4.49', Contrast::format(4.4999));
        self::assertSame('4.50', Contrast::format(4.505720081544952));
        self::assertSame('21.00', Contrast::format(21.0));
    }

    public function testAColorThatIsNotOpaqueHexFails(): void
    {
        $this->expectExceptionMessage('Contrast needs an opaque #rrggbb color, not transparent.');
        Contrast::ratio('transparent', '#000000');
    }
}
