<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\OpenTelemetry;

use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Infrastructure\OpenTelemetry\HttpSpanCapturePolicy;
use Fiber;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpSpanCapturePolicyTest extends TestCase
{
    public function testChangesAreReadAtTheRefreshBoundary(): void
    {
        $now = 10.0;
        $settings = $this->createMock(SystemSettingsPortInterface::class);
        $settings->expects(self::exactly(3))->method('get')->with('diagnostics.spans_enabled')->willReturn(true, false, true);
        $policy = new HttpSpanCapturePolicy($settings, static function () use (&$now): float {
            return $now;
        });

        self::assertTrue($policy->enabled());
        $now = 14.999;
        self::assertTrue($policy->enabled());
        $now = 15.0;
        self::assertFalse($policy->enabled());
        $now = 19.999;
        self::assertFalse($policy->enabled());
        $now = 20.0;
        self::assertTrue($policy->enabled());
    }

    public function testLookupFailureDisablesCaptureAndThrottlesRetry(): void
    {
        $now = 0.0;
        $reads = 0;
        $settings = $this->createMock(SystemSettingsPortInterface::class);
        $settings->expects(self::exactly(3))->method('get')->willReturnCallback(static function () use (&$reads): bool {
            if (++$reads === 2) {
                throw new RuntimeException('Store unavailable');
            }

            return true;
        });
        $policy = new HttpSpanCapturePolicy($settings, static function () use (&$now): float {
            return $now;
        });

        self::assertTrue($policy->enabled());
        $now = 5.0;
        self::assertFalse($policy->enabled());
        $now = 9.999;
        self::assertFalse($policy->enabled());
        $now = 10.0;
        self::assertTrue($policy->enabled());
    }

    public function testOverlappingRefreshesSkipCaptureEvenAfterTheDeadline(): void
    {
        $now = 0.0;
        $settings = $this->createMock(SystemSettingsPortInterface::class);
        $settings->expects(self::exactly(2))->method('get')->willReturnCallback(static function (): bool {
            Fiber::suspend();

            return true;
        });
        $policy = new HttpSpanCapturePolicy($settings, static function () use (&$now): float {
            return $now;
        });
        $refresh = new Fiber($policy->enabled(...));
        $refresh->start();
        self::assertFalse($policy->enabled());
        $now = 6.0;
        self::assertFalse($policy->enabled());
        $refresh->resume();
        self::assertTrue($refresh->getReturn());
        // Completing a slow read still gives the worker a bounded quiet interval.
        self::assertTrue($policy->enabled());
        $now = 11.0;
        $refresh = new Fiber($policy->enabled(...));
        $refresh->start();
        self::assertFalse($policy->enabled());
        $refresh->resume();
        self::assertTrue($refresh->getReturn());
    }
}
