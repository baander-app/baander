<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SerialQueueTest extends TestCase
{
    public function testBoundedQueuePreservesOrderAndConsumerOwnershipAndReportsFailures(): void
    {
        $script = <<<'CHILD'
require $argv[1];
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Component\Concurrency\SerialQueue;
$received = [];
$owners = [];
$released = [];
$blocked = false;
$failureReachedCaller = false;
$taskFailureReachedCaller = false;
Swoole\Coroutine\run(static function () use (&$received, &$owners, &$released, &$blocked, &$failureReachedCaller, &$taskFailureReachedCaller): void {
    $queue = new SerialQueue(new Adapter(), static function (mixed $payload) use (&$received, &$owners): void {
        $owners[] = Swoole\Coroutine::getCid();
        Swoole\Coroutine::sleep(0.002);
        if ($payload === 'bad') { throw new LogicException('write failed'); }
        $received[] = $payload;
    }, 1, static function () use (&$released): void { $released[] = Swoole\Coroutine::getCid(); }, 0.01);
    $queue->submit(1);
    $queue->submit(2);
    $before = count($received);
    $queue->submit(3);
    $blocked = $before === 0 && count($received) >= 1;
    $queue->drain();
    if ($received !== [1, 2, 3] || !$queue->isEmpty()) { exit(1); }
    $queue->runInConsumer(static function () use (&$owners): void { $owners[] = Swoole\Coroutine::getCid(); });
    $queue->submit('bad');
    try { $queue->drain(); } catch (RuntimeException $error) { $failureReachedCaller = str_contains($error->getMessage(), 'write failed'); }
    $queue->submit(4);
    $queue->drain();
    try { $queue->runInConsumer(static function (): void { throw new LogicException('close failed'); }); }
    catch (LogicException $error) { $taskFailureReachedCaller = $error->getMessage() === 'close failed'; }
    Swoole\Coroutine::sleep(0.02);
});
if ($received !== [1, 2, 3, 4] || count(array_unique($owners)) !== 1 || $released !== [$owners[0]] || !$blocked || !$failureReachedCaller || !$taskFailureReachedCaller) {
    fwrite(STDERR, json_encode([$received, $owners, $released, $blocked, $failureReachedCaller])); exit(3);
}
echo "bounded FIFO, single owner, flush, failure reporting and continuation verified\n";
CHILD;
        $process = $this->probe($script);
        self::assertSame("bounded FIFO, single owner, flush, failure reporting and continuation verified\n", $process->getOutput());
        self::assertStringContainsString('write failed', $process->getErrorOutput());
    }

    public function testSchedulingFailureCanRetryWithoutOrphanedPayloads(): void
    {
        $script = <<<'CHILD'
require $argv[1];
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Component\Concurrency\SerialQueue;
$received = [];
$queue = new SerialQueue(new Adapter(), static function (mixed $payload) use (&$received): void { $received[] = $payload; }, 1);
Swoole\Coroutine::set(['max_coroutine' => 1]);
$rejected = false;
set_error_handler(static fn(int $severity, string $message): bool => $severity === E_WARNING && str_contains($message, 'exceed max number of coroutine'));
Swoole\Coroutine\run(static function () use ($queue, &$rejected): void {
    try { $queue->submit('rejected'); } catch (RuntimeException $error) { $rejected = true; }
});
restore_error_handler();
if (!$rejected || !$queue->isEmpty()) { exit(1); }
Swoole\Coroutine::set(['max_coroutine' => 8]);
Swoole\Coroutine\run(static function () use ($queue): void { $queue->submit('accepted'); $queue->drain(); });
if ($received !== ['accepted'] || !$queue->isEmpty()) { exit(2); }
echo "scheduling failure rejected; later submission drained\n";
CHILD;
        $process = $this->probe($script);
        self::assertSame("scheduling failure rejected; later submission drained\n", $process->getOutput());
    }

    private function probe(string $script): Process
    {
        $process = CoroutineProbe::create(['-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(10);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());

        return $process;
    }
}
