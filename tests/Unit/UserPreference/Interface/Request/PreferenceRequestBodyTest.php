<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Interface\Request;

use App\UserPreference\Interface\Request\PreferenceRequestBody;
use PHPUnit\Framework\TestCase;

final class PreferenceRequestBodyTest extends TestCase
{
    public function testSavePreservesNestedJsonValues(): void
    {
        $body = '{"payload":{"enabled":false,"bands":[{"gain":-3,"q":0.7}],"optional":null,"emptyList":[],"emptyObject":{},"name":"音楽"},"version":0}';
        $parsed = (new PreferenceRequestBody())->save($body);

        self::assertSame(0, $parsed['version']);
        self::assertFalse($parsed['payload']['enabled']);
        self::assertSame([['gain' => -3, 'q' => 0.7]], $parsed['payload']['bands']);
        self::assertNull($parsed['payload']['optional']);
        self::assertSame([], $parsed['payload']['emptyList']);
        self::assertInstanceOf(\stdClass::class, $parsed['payload']['emptyObject']);
        self::assertEquals(json_decode($body), json_decode(json_encode($parsed, JSON_THROW_ON_ERROR)));
    }

    public function testSaveAcceptsTheLargestRepresentableIntegerVersion(): void
    {
        $parsed = (new PreferenceRequestBody())->save('{"payload":{"enabled":true},"version":' . PHP_INT_MAX . '}');
        self::assertSame(PHP_INT_MAX, $parsed['version']);
    }

    public function testRollbackReturnsTheExplicitPositiveVersion(): void
    {
        self::assertSame(3, (new PreferenceRequestBody())->rollback('{"version":3}'));
    }
}
