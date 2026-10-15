<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\Service;

use App\Auth\Application\Service\WebSocketOriginPolicy;
use PHPUnit\Framework\TestCase;

final class WebSocketOriginPolicyTest extends TestCase
{
    public function testDefaultUsesSerializedApplicationOriginRatherThanItsPathOrDefaultPort(): void
    {
        $policy = new WebSocketOriginPolicy('https://BAANDER.app:443/application/path');
        self::assertSame(['https://baander.app'], $policy->allowedOrigins());
        self::assertTrue($policy->allows('https://baander.app'));
        foreach (['', 'null', 'https://foreign.baander.app', 'https://baander.app/', 'https://baander.app:443', 'https://baander.app/path', 'https://baander.app#', "https://baander.app\n", 'https://user@baander.app'] as $origin) {
            self::assertFalse($policy->allows($origin), $origin);
        }
    }

    public function testExplicitEmptyAllowlistDeniesTheApplicationOrigin(): void
    {
        self::assertFalse((new WebSocketOriginPolicy('https://baander.app', []))->allows('https://baander.app'));
    }

    public function testExplicitPortAndIpv6AreSerializedWithoutLosingThePort(): void
    {
        $policy = new WebSocketOriginPolicy('https://[::1]:8443/application');
        self::assertTrue($policy->allows('https://[::1]:8443'));
        self::assertFalse($policy->allows('https://[::1]'));
    }

    public function testConfiguredOriginMustNotContainCredentialsOrAPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WebSocketOriginPolicy('https://baander.app', ['https://baander.app/path']);
    }
}
