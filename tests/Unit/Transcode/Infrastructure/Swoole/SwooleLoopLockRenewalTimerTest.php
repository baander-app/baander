<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SwooleLoopLockRenewalTimerTest extends TestCase
{
    public function testRealTimerRepeatsAndCanBeClearedWithoutLeavingScheduledWork(): void
    {
        self::assertTrue(extension_loaded('swoole'), 'The actual timer adapter requires Swoole.');
        // Isolate the real reactor from PHPUnit and bound the child process even
        // if the adapter fails to clear the repeating timer.
        $script = <<<'PHP'
require $argv[1];
$adapter = new App\Transcode\Infrastructure\Swoole\SwooleLoopLockRenewalTimer();
$calls = 0;
$timerId = 0;
$watchdog = Swoole\Timer::after(1000, static function (): void {
    fwrite(STDERR, "Timer did not terminate.\n");
    exit(1);
});
$timerId = $adapter->tick(10, static function () use ($adapter, &$calls, &$timerId, $watchdog): void {
    if (++$calls === 2) {
        $adapter->clear($timerId);
        Swoole\Timer::clear($watchdog);
    }
});
Swoole\Event::wait();
if ($calls !== 2 || Swoole\Timer::exists($timerId)) {
    fwrite(STDERR, "Unexpected timer state.\n");
    exit(2);
}
echo "two callbacks, timer cleared\n";
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(3);

        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame("two callbacks, timer cleared\n", $process->getOutput());
    }

    public function testEachTickReleasesThePooledServicesItsCoroutineTook(): void
    {
        self::assertTrue(extension_loaded('swoole'), 'The actual timer adapter requires Swoole.');
        // A renewal that loses ownership logs through the pooled transcode logger.
        // Swoole runs each tick in a new coroutine, which the bundle never releases.
        $script = <<<'PHP'
require $argv[1];
final class RecordingPool implements SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool {
    /** @var list<int> */
    public array $released = [];
    public function get(): object { return new stdClass(); }
    public function releaseFromCoroutine(int $cId): void { $this->released[] = $cId; }
    public function getAssignedCount(): int { return 0; }
    public function getFreeCount(): int { return 0; }
    public function getInstancesLimit(): int { return 1; }
}
$pool = new RecordingPool();
$adapter = new App\Transcode\Infrastructure\Swoole\SwooleLoopLockRenewalTimer(
    new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper(
        new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer([0 => [$pool]]),
        new SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole(),
    ),
);
$ticks = [];
$timerId = 0;
$watchdog = Swoole\Timer::after(1000, static function (): void {
    fwrite(STDERR, "Timer did not terminate.\n");
    exit(1);
});
$timerId = $adapter->tick(10, static function () use ($adapter, &$ticks, &$timerId, $watchdog): void {
    $ticks[] = Swoole\Coroutine::getCid();
    if (count($ticks) === 2) {
        $adapter->clear($timerId);
        Swoole\Timer::clear($watchdog);
    }
});
Swoole\Event::wait();
if (count($ticks) !== 2 || min($ticks) < 1 || $pool->released !== $ticks) {
    fwrite(STDERR, sprintf("Ticks ran in coroutines %s; released %s.\n", json_encode($ticks), json_encode($pool->released)));
    exit(2);
}
echo "each tick released its coroutine\n";
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(3);

        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame("each tick released its coroutine\n", $process->getOutput());
    }
}
