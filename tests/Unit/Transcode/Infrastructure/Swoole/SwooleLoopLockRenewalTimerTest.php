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
}
