<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Drain;

use App\Shared\Infrastructure\Swoole\Drain\DrainBudget;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class DrainBudgetTest extends TestCase
{
    #[TestWith([30, 13, 6])]
    #[TestWith([8, 2, 1])]
    #[TestWith([10, 3, 1])]
    public function testTheWindowAndWorkerStopWaitComeFromTheDeadline(int $deadline, int $window, int $maxWait): void
    {
        $budget = new DrainBudget($deadline);

        self::assertSame($deadline, $budget->deadlineSeconds);
        self::assertSame($window, $budget->windowSeconds);
        self::assertSame($maxWait, $budget->maxWaitSeconds);
        // The window, Swoole's two worker stops and the pool's stop with its margin fit in D.
        self::assertLessThanOrEqual($deadline, $window + 2 * $maxWait + 4);
    }

    public function testADeadlineBelowEightSecondsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WEB_DRAIN_SECONDS must be at least 8; it is 7.');

        new DrainBudget(7);
    }
}
