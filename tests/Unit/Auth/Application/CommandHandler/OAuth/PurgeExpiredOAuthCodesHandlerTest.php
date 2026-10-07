<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\OAuth;

use App\Auth\Application\Command\OAuth\PurgeExpiredOAuthCodesCommand;
use App\Auth\Application\CommandHandler\OAuth\PurgeExpiredOAuthCodesHandler;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PurgeExpiredOAuthCodesHandlerTest extends TestCase
{
    public function testDeletesCodesExpiredMoreThanAnHourAgoAndReportsTheCounts(): void
    {
        $now = new DateTimeImmutable('2026-10-07T03:30:00.250000+00:00');
        $cutoffs = [];

        $authCodes = $this->createMock(AuthCodeRepositoryInterface::class);
        $authCodes->expects($this->once())->method('deleteExpiredBefore')
            ->willReturnCallback(static function (DateTimeImmutable $cutoff) use (&$cutoffs): int {
                $cutoffs[] = $cutoff;

                return 3;
            });
        $deviceCodes = $this->createMock(DeviceCodeRepositoryInterface::class);
        $deviceCodes->expects($this->once())->method('deleteExpiredBefore')
            ->willReturnCallback(static function (DateTimeImmutable $cutoff) use (&$cutoffs): int {
                $cutoffs[] = $cutoff;

                return 2;
            });

        $result = (new PurgeExpiredOAuthCodesHandler($authCodes, $deviceCodes, new MockClock($now)))(new PurgeExpiredOAuthCodesCommand());

        self::assertSame(3, $result->authorizationCodes);
        self::assertSame(2, $result->deviceCodes);
        $expected = new DateTimeImmutable('2026-10-07T02:30:00.250000+00:00');
        self::assertEquals($expected, $result->expiredBefore);
        self::assertEquals([$expected, $expected], $cutoffs, 'Both kinds of code share one cutoff, fractions included.');
    }
}
