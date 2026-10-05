<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Http;

use App\Shared\Application\Http\BaanderHeader;
use PHPUnit\Framework\TestCase;

final class BaanderHeaderTest extends TestCase
{
    public function testWireNamesAreUniqueAndPrefixed(): void
    {
        $names = array_map(static fn (BaanderHeader $header): string => $header->value, BaanderHeader::cases());

        self::assertCount(count($names), array_unique($names));
        foreach ($names as $name) {
            self::assertStringStartsWith('X-Baander-', $name);
        }
    }

    public function testSymfonyServerKeyMatchesPublicWireName(): void
    {
        self::assertSame('X-Baander-Device-Id', BaanderHeader::DeviceId->value);
        self::assertSame('HTTP_X_BAANDER_DEVICE_ID', BaanderHeader::DeviceId->serverKey());
        self::assertSame('HTTP_X_BAANDER_TEST_USER_ID', BaanderHeader::TestUserId->serverKey());
    }
}
