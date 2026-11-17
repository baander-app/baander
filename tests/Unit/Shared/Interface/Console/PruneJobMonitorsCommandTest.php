<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Console;

use App\Shared\Application\DTO\JobMonitorPruneResult;
use App\Shared\Application\Port\JobMonitorAdministrationInterface;
use App\Shared\Interface\Console\PruneJobMonitorsCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneJobMonitorsCommandTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function valuesThatAreNotIntegers(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'digits followed by letters' => ['5x'];
        yield 'a fraction' => ['1.5'];
    }

    #[DataProvider('valuesThatAreNotIntegers')]
    public function testADaysValueThatIsNotAnIntegerIsRefusedBeforeAnythingIsPruned(string $days): void
    {
        $monitor = $this->createMock(JobMonitorAdministrationInterface::class);
        $monitor->expects(self::never())->method('prune');
        $tester = new CommandTester(new PruneJobMonitorsCommand($monitor));

        self::assertSame(Command::INVALID, $tester->execute(['--days' => $days], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('--days must be an integer.', $tester->getErrorOutput());
    }

    public function testPrunesTheRecordsOlderThanTheGivenNumberOfDays(): void
    {
        $monitor = $this->createMock(JobMonitorAdministrationInterface::class);
        $monitor->expects(self::once())
            ->method('prune')
            ->with(30, false)
            ->willReturn(new JobMonitorPruneResult(12, new \DateTimeImmutable('-30 days')));
        $tester = new CommandTester(new PruneJobMonitorsCommand($monitor));

        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '30']));
        self::assertStringContainsString('Pruned 12 job monitor(s)', $tester->getDisplay());
    }

    public function testWithoutDaysPrunesTheRecordsOlderThanSevenDays(): void
    {
        $monitor = $this->createMock(JobMonitorAdministrationInterface::class);
        $monitor->expects(self::once())
            ->method('prune')
            ->with(7, false)
            ->willReturn(new JobMonitorPruneResult(0, new \DateTimeImmutable('-7 days')));
        $tester = new CommandTester(new PruneJobMonitorsCommand($monitor));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
    }
}
