<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\OpenTelemetry;

use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Application\Service\SettingDefinitionRegistry;
use App\Shared\Application\Service\SystemSettings;
use App\Shared\Application\Settings\DiagnosticsSettingDefinitions;
use App\Shared\Infrastructure\OpenTelemetry\HttpSpanCapturePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiagnosticsSettingDefinitionsTest extends TestCase
{
    #[DataProvider('environmentDefaults')]
    public function testDefaultsOverridesAndResetAgreeWithCaptureAndPanelMetadata(string $environment, bool $default): void
    {
        $now = 0.0;
        $stored = null;
        $store = $this->createStub(SystemSettingStoreInterface::class);
        $store->method('find')->willReturnCallback(static function () use (&$stored): mixed {
            return $stored;
        });
        $settings = new SystemSettings(new SettingDefinitionRegistry([new DiagnosticsSettingDefinitions($environment)]), $store);
        $policy = new HttpSpanCapturePolicy($settings, static function () use (&$now): float {
            return $now;
        });
        $key = DiagnosticsSettingDefinitions::SPANS_ENABLED;

        self::assertSame($default, $settings->definition($key)->default);
        self::assertTrue($settings->definition($key)->enforced);
        self::assertSame($default, $settings->entry($key)->value);
        self::assertFalse($settings->entry($key)->isExplicit());
        self::assertSame($default, $policy->enabled());

        $stored = true;
        $now += 5.0;
        self::assertTrue($settings->entry($key)->value);
        self::assertTrue($settings->entry($key)->isExplicit());
        self::assertTrue($policy->enabled());

        $stored = false;
        $now += 5.0;
        self::assertFalse($settings->entry($key)->value);
        self::assertFalse($policy->enabled());

        $stored = null;
        $now += 5.0;
        self::assertSame($default, $settings->entry($key)->value);
        self::assertFalse($settings->entry($key)->isExplicit());
        self::assertSame($default, $policy->enabled());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function environmentDefaults(): iterable
    {
        yield 'development' => ['dev', true];
        yield 'production' => ['prod', false];
        yield 'staging' => ['staging', false];
        yield 'test' => ['test', false];
    }
}
