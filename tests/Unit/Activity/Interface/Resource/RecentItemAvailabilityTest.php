<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Interface\Resource;

use App\Activity\Interface\Resource\RecentItemResource;
use App\Shared\Domain\Model\PublicId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecentItemAvailabilityTest extends TestCase
{
    /** @return iterable<string, array{?bool}> */
    public static function observations(): iterable
    {
        yield 'available' => [true];
        yield 'unavailable' => [false];
        yield 'no observation' => [null];
    }

    #[DataProvider('observations')]
    public function testRecentShortcutPreservesObservedSongAvailability(?bool $observed): void
    {
        $source = ['publicId' => (new PublicId())->toString(), 'activityType' => 'play'];
        if ($observed !== null) {
            $source['songAvailable'] = $observed;
        }

        self::assertSame($observed ?? false, RecentItemResource::from($source)['songAvailable']);
    }
}
