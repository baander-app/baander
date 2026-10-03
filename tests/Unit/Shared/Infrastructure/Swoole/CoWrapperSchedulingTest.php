<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CoWrapperSchedulingTest extends TestCase
{
    public function testExhaustedCoroutineLimitRejectsHandoffAndNormalSchedulingStillRuns(): void
    {
        $script = <<<'CHILD'
require $argv[1];
$wrapper = new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper(
    new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer([]),
    new SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole(),
);
Swoole\Coroutine::set(['max_coroutine' => 1]);
$rejected = false;
$ran = false;
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if ($severity === E_WARNING && str_contains($message, 'exceed max number of coroutine')) {
        $warnings[] = $message;
        return true;
    }
    return false;
});
Swoole\Coroutine\run(static function () use ($wrapper, &$ran, &$rejected): void {
    try {
        $wrapper->go(static function () use (&$ran): void { $ran = true; });
    } catch (RuntimeException $error) {
        $rejected = $error->getMessage() === 'Unable to create coroutine.';
    }
});
restore_error_handler();
if (!$rejected || $ran || count($warnings) !== 1) { exit(1); }
Swoole\Coroutine::set(['max_coroutine' => 8]);
Swoole\Coroutine\run(static function () use ($wrapper, &$ran): void {
    $wrapper->go(static function () use (&$ran): void { $ran = true; });
});
if (!$ran) { exit(2); }
echo "rejected exhaustion; scheduled normally\n";
CHILD;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(5);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame("rejected exhaustion; scheduled normally\n", $process->getOutput());
    }
}
