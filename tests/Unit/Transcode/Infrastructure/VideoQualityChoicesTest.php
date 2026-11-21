<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure;

use App\QoL\Application\Port\AllowedQualityTiersPortInterface;
use App\Transcode\Infrastructure\VideoQualityChoices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoQualityChoicesTest extends TestCase
{
    public function testQualifiedEncoderOffersAllCanonicalChoices(): void
    {
        $this->withChoices( function (VideoQualityChoices $choices): void {
            $available = $choices->choices(3840, 2160);
            self::assertSame(['360p', '480p', '720p', '1080p', '1440p', '4K'], array_column($available, 'id'));
            self::assertSame(['id' => '4K', 'label' => '2160p / 4K', 'width' => 3840, 'height' => 2160], $available[5]);
        });
    }

    /** @param list<string> $ids */
    #[DataProvider('sourceDimensions')]
    public function testChoicesNeverUpscaleEitherDimension(?int $width, ?int $height, array $ids): void
    {
        $this->withChoices( static function (VideoQualityChoices $choices) use ($width, $height, $ids): void {
            self::assertSame($ids, array_column($choices->choices($width, $height), 'id'));
        });
    }

    /** @return iterable<string, array{?int, ?int, list<string>}> */
    public static function sourceDimensions(): iterable
    {
        yield 'ultrawide' => [3840, 1600, ['360p', '480p', '720p', '1080p', '1440p']];
        yield 'portrait' => [1080, 1920, ['360p', '480p']];
        yield 'small' => [320, 240, []];
        yield 'unknown width' => [null, 2160, []];
        yield 'unknown height' => [3840, null, []];
        yield 'zero width' => [0, 2160, []];
    }

    public function testStableQualityPolicyFiltersChoices(): void
    {
        $policy = $this->createMock(AllowedQualityTiersPortInterface::class);
        $policy->expects(self::never())->method('allowedTierNames');
        $policy->expects(self::once())->method('qualifiedTierNames')->willReturn(['360p', '720p', '1440p']);
        self::assertSame(
            ['360p', '720p', '1440p'],
            array_column((new VideoQualityChoices($policy))->choices(3840, 2160), 'id'),
        );
    }

    /** @param callable(VideoQualityChoices): void $assertions */
    private function withChoices(callable $assertions): void
    {
        $policy = $this->createStub(AllowedQualityTiersPortInterface::class);
        $policy->method('qualifiedTierNames')->willReturn(['360p', '480p', '720p', '1080p', '1440p', '4K']);
        $assertions(new VideoQualityChoices($policy));
    }
}
