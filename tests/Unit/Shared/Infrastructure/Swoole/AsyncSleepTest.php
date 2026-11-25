<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\Async;
use App\Shared\Infrastructure\Swoole\CoroutineCanceledException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Symfony\Component\Process\Process;

/**
 * The sleep that lasts its full time although signals arrive while it sleeps.
 */
final class AsyncSleepTest extends TestCase
{
    /**
     * Sleeps three seconds while an alarm arrives every second, as a Messenger consumer's
     * keepalive alarm does, and tells how long the sleep took and how many alarms arrived.
     */
    private const string CHILD = <<<'PHP'
        require $argv[1];
        pcntl_async_signals(true);
        $alarms = 0;
        pcntl_signal(SIGALRM, static function () use (&$alarms): void {
            ++$alarms;
            pcntl_alarm(1);
        });
        pcntl_alarm(1);
        $started = hrtime(true);
        \App\Shared\Infrastructure\Swoole\Async::sleepThroughSignals(3.0);
        echo json_encode(['seconds' => (hrtime(true) - $started) / 1e9, 'alarms' => $alarms], JSON_THROW_ON_ERROR);
        PHP;

    public function testAThreeSecondSleepLastsThreeSecondsWhileAnAlarmArrivesEverySecond(): void
    {
        $child = new Process([PHP_BINARY, '-r', self::CHILD, dirname(__DIR__, 5) . '/vendor/autoload.php'], timeout: 15);

        self::assertSame(0, $child->run(), $child->getErrorOutput());
        $slept = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($slept);
        self::assertGreaterThanOrEqual(3.0, $slept['seconds']);
        self::assertGreaterThanOrEqual(2, $slept['alarms'], 'The alarm arrived while the process slept.');
    }

    #[DataProvider('waitsOfZeroOrLess')]
    public function testAWaitOfZeroOrLessReturnsAtOnce(float $seconds): void
    {
        $started = hrtime(true);

        Async::sleepThroughSignals($seconds);

        self::assertLessThan(0.1, (hrtime(true) - $started) / 1e9);
    }

    #[DataProvider('waitsOfZeroOrLess')]
    public function testAWaitOfZeroOrLessReturnsAtOnceInACoroutineWithoutAWarning(float $seconds): void
    {
        // Swoole warns about a sleep shorter than a millisecond instead of sleeping.
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        $started = hrtime(true);

        try {
            \Swoole\Coroutine\run(static function () use ($seconds): void {
                Async::sleepThroughSignals($seconds);
            });
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertLessThan(0.1, (hrtime(true) - $started) / 1e9);
    }

    /** @return iterable<string, array{float}> */
    public static function waitsOfZeroOrLess(): iterable
    {
        yield 'zero' => [0.0];
        yield 'less than zero' => [-1.0];
    }

    public function testCancellationCheckpointAllowsOrdinaryAndUncancelledExecution(): void
    {
        Async::throwIfCanceled();
        $continued = false;
        \Swoole\Coroutine\run(static function () use (&$continued): void {
            Async::throwIfCanceled();
            $continued = true;
        });
        self::assertTrue($continued);
    }

    public function testACancelledWaitInACoroutineStillThrows(): void
    {
        $cancelled = false;

        \Swoole\Coroutine\run(static function () use (&$cancelled): void {
            // The new coroutine runs until its sleep yields, so it is asleep when it is cancelled.
            $sleeper = Coroutine::create(static function () use (&$cancelled): void {
                try {
                    Async::sleepThroughSignals(5.0);
                } catch (CoroutineCanceledException) {
                    $cancelled = true;
                }
            });
            Coroutine::cancel($sleeper);
        });

        self::assertTrue($cancelled);
    }
}
