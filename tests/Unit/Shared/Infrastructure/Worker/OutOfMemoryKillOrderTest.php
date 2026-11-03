<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use App\Shared\Infrastructure\Worker\OutOfMemoryKillOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutOfMemoryKillOrderTest extends TestCase
{
    public function testMediaWorkIsKilledBeforeTheExecutionUnitAndTheControlUnitLast(): void
    {
        self::assertSame(-500, OutOfMemoryKillOrder::unit(DeploymentUnit::Control));
        self::assertSame(300, OutOfMemoryKillOrder::unit(DeploymentUnit::Execution));
        self::assertGreaterThan(OutOfMemoryKillOrder::EXECUTION_UNIT, OutOfMemoryKillOrder::MEDIA_WORK);
        self::assertGreaterThan(OutOfMemoryKillOrder::CONTROL_UNIT, OutOfMemoryKillOrder::EXECUTION_UNIT);
        self::assertLessThan(0, OutOfMemoryKillOrder::CONTROL_UNIT);
        self::assertLessThanOrEqual(1000, OutOfMemoryKillOrder::MEDIA_WORK);
    }

    public function testRootfulDaemonAppliesTheKillOrder(): void
    {
        OutOfMemoryKillOrder::assertApplicable(['name=seccomp,profile=builtin', 'name=selinux', 'name=cgroupns']);
        $this->addToAssertionCount(1);
    }

    /** @param list<mixed>|null $options */
    #[DataProvider('unusableDaemons')]
    public function testRootlessOrUnreportedDaemonIsRefusedWithANamedReason(mixed $options, string $reason): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($reason);
        OutOfMemoryKillOrder::assertApplicable($options);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unusableDaemons(): iterable
    {
        yield 'rootless' => [['name=seccomp,profile=builtin', 'name=rootless', 'name=cgroupns'], 'Rootless Docker'];
        yield 'rootless with options' => [['name=rootless,extra=1'], 'Rootless Docker'];
        yield 'unreported' => [null, 'did not report'];
        yield 'invalid entry' => [[1], 'invalid security options'];
    }

    public function testMediaWorkRaisesItsOwnScoreAboveItsParent(): void
    {
        if (!is_readable('/proc/self/oom_score_adj')) {
            self::markTestSkipped('Requires Linux out-of-memory scores.');
        }
        $parent = (int) trim((string) file_get_contents('/proc/self/oom_score_adj'));
        if ($parent >= OutOfMemoryKillOrder::MEDIA_WORK) {
            self::markTestSkipped('The test process already runs at or above the media work score.');
        }
        $code = 'require $argv[1]; \App\Shared\Infrastructure\Worker\OutOfMemoryKillOrder::raiseToMediaWork(); echo trim(file_get_contents("/proc/self/oom_score_adj"));';
        $child = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 5) . '/vendor/autoload.php'], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($child));
        self::assertSame((string) OutOfMemoryKillOrder::MEDIA_WORK, $output);
        self::assertSame($parent, (int) trim((string) file_get_contents('/proc/self/oom_score_adj')), 'Raising a child leaves its parent unchanged.');
    }

    public function testUnwritableScoreFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        OutOfMemoryKillOrder::raiseToMediaWork(sys_get_temp_dir() . '/baander-missing-' . bin2hex(random_bytes(8)) . '/oom_score_adj');
    }
}
