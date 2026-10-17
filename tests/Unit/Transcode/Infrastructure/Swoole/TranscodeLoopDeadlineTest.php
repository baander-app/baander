<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Transcode\Infrastructure\Swoole\TranscodeLoopOwnership;
use App\Transcode\Infrastructure\Swoole\TranscodeLoopOwnershipLost;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TranscodeLoopDeadlineTest extends TestCase
{
    public function testUnconfirmedOwnershipCannotAuthorizeWork(): void
    {
        $ownership = new TranscodeLoopOwnership(static fn(int $seconds): bool => false);

        self::assertFalse($ownership->isActive());
        self::assertFalse($ownership->isLost());
        $this->expectException(TranscodeLoopOwnershipLost::class);
        $ownership->assertOwned();
    }

    public function testExactExpiryRejectsWorkBeforeAnyTimerCallbackRuns(): void
    {
        $now = 1_000_000_000;
        $lease = $this->createMock(RenewalProbe::class);
        $lease->expects(self::once())->method('renew')->willReturn(true);
        $ownership = new TranscodeLoopOwnership(\Closure::fromCallable([$lease, 'renew']), static function () use (&$now): int { return $now; });
        self::assertTrue($ownership->renew(30));

        $now = 30_999_999_999;
        $ownership->assertOwned();
        self::assertTrue($ownership->isActive());

        $now = 31_000_000_000;
        self::assertFalse($ownership->isActive());
        self::assertTrue($ownership->isLost());
        self::assertFalse($ownership->renew(30), 'Expiry must not issue a new database renewal.');
        $this->expectException(TranscodeLoopOwnershipLost::class);
        $ownership->assertOwned();
    }

    public function testInitialSuccessfulReplyCannotGrantTimeAlreadyConsumedByIo(): void
    {
        $now = 0;
        $lease = $this->createMock(RenewalProbe::class);
        $lease->expects(self::once())->method('renew')->willReturnCallback(static function () use (&$now): bool {
            $now = 30_000_000_000;
            return true;
        });
        $ownership = new TranscodeLoopOwnership(\Closure::fromCallable([$lease, 'renew']), static function () use (&$now): int { return $now; });

        self::assertFalse($ownership->renew(30));
        self::assertTrue($ownership->isLost());
        self::assertFalse($ownership->renew(30));
    }

    public function testRenewalReplyAfterPreviousDeadlineCannotReviveOwnership(): void
    {
        $now = 0;
        $requests = 0;
        $lease = $this->createMock(RenewalProbe::class);
        $lease->expects(self::exactly(2))->method('renew')->willReturnCallback(static function () use (&$now, &$requests): bool {
            if (++$requests === 2) {
                $now = 30_000_000_000;
            }
            return true;
        });
        $ownership = new TranscodeLoopOwnership(\Closure::fromCallable([$lease, 'renew']), static function () use (&$now): int { return $now; });
        self::assertTrue($ownership->renew(30));
        $now = 20_000_000_000;

        self::assertFalse($ownership->renew(30));
        self::assertTrue($ownership->isLost());
        self::assertFalse($ownership->renew(30));
    }

    public function testTimelyRenewalUsesRequestStartRatherThanReplyTime(): void
    {
        $now = 0;
        $requests = 0;
        $lease = $this->createMock(RenewalProbe::class);
        $lease->expects(self::exactly(2))->method('renew')->willReturnCallback(static function () use (&$now, &$requests): bool {
            if (++$requests === 2) {
                $now = 25_000_000_000;
            }
            return true;
        });
        $ownership = new TranscodeLoopOwnership(\Closure::fromCallable([$lease, 'renew']), static function () use (&$now): int { return $now; });
        self::assertTrue($ownership->renew(30));
        $now = 20_000_000_000;
        self::assertTrue($ownership->renew(30));
        $now = 49_999_999_999;
        self::assertTrue($ownership->isActive());
        $now = 50_000_000_000;
        self::assertFalse($ownership->isActive());
    }

    public function testClosureDuringRenewalRejectsSuccessfulReply(): void
    {
        $now = 0;
        $lease = $this->createMock(RenewalProbe::class);
        $ownership = new TranscodeLoopOwnership(\Closure::fromCallable([$lease, 'renew']), static function () use (&$now): int { return $now; });
        $lease->expects(self::once())->method('renew')->willReturnCallback(static function () use ($ownership): bool {
            $ownership->close();
            return true;
        });

        self::assertFalse($ownership->renew(30));
        self::assertTrue($ownership->isClosed());
        self::assertFalse($ownership->isActive());
        self::assertFalse($ownership->renew(30));
    }

    public function testOverlappingRenewalDoesNotIssueAnotherRedisOperation(): void
    {
        $now = 0;
        $requests = 0;
        $lease = $this->createMock(RenewalProbe::class);
        $ownership = new TranscodeLoopOwnership(\Closure::fromCallable([$lease, 'renew']), static function () use (&$now): int { return $now; });
        $lease->expects(self::exactly(2))->method('renew')->willReturnCallback(static function () use ($ownership, &$requests): bool {
            if (++$requests === 1) {
                self::assertFalse($ownership->renew(30), 'The initial grant is still unconfirmed.');
            } else {
                self::assertTrue($ownership->renew(30), 'A pending renewal retains only the previous grant.');
            }
            return true;
        });

        self::assertTrue($ownership->renew(30));
        $now = 20_000_000_000;
        self::assertTrue($ownership->renew(30));
        self::assertFalse($ownership->isLost());
    }

    public function testFailedRenewalPermanentlyRevokesOwnership(): void
    {
        $lease = $this->createMock(RenewalProbe::class);
        $lease->expects(self::once())->method('renew')->willReturn(false);
        $ownership = new TranscodeLoopOwnership(\Closure::fromCallable([$lease, 'renew']), static fn (): int => 0);

        self::assertFalse($ownership->renew(30));
        self::assertTrue($ownership->isLost());
        self::assertFalse($ownership->renew(30));
    }

    public function testExpiryObservedDuringPendingRenewalRejectsItsSuccessfulReply(): void
    {
        $now = 0;
        $requests = 0;
        $lease = $this->createMock(RenewalProbe::class);
        $ownership = new TranscodeLoopOwnership(\Closure::fromCallable([$lease, 'renew']), static function () use (&$now): int { return $now; });
        $lease->expects(self::exactly(2))->method('renew')->willReturnCallback(static function () use (&$now, $ownership, &$requests): bool {
            if (++$requests === 2) {
                $now = 30_000_000_000;
                self::assertFalse($ownership->renew(30));
                self::assertTrue($ownership->isLost());
            }
            return true;
        });
        self::assertTrue($ownership->renew(30));
        $now = 20_000_000_000;

        self::assertFalse($ownership->renew(30));
        self::assertTrue($ownership->isLost());
        self::assertFalse($ownership->renew(30));
    }

    public function testRenewalExceptionPermanentlyRevokesOwnership(): void
    {
        $lease = $this->createMock(RenewalProbe::class);
        $lease->expects(self::once())->method('renew')->willThrowException(new RuntimeException('Redis unavailable'));
        $ownership = new TranscodeLoopOwnership(\Closure::fromCallable([$lease, 'renew']), static fn (): int => 0);

        self::assertFalse($ownership->renew(30));
        self::assertTrue($ownership->isLost());
        self::assertFalse($ownership->renew(30));
    }
}

interface RenewalProbe
{
    public function renew(int $seconds): bool;
}
