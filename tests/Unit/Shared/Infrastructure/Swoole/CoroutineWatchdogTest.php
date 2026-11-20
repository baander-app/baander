<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Tests\Fixtures\Swoole\CoroutineProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CoroutineWatchdogTest extends TestCase
{
    public function testActiveSerialQueueWaitingBetweenPayloadsIsNotReportedAsStalled(): void
    {
        $script = <<<'CHILD'
require $argv[1];
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Component\Concurrency\SerialQueue;
use SwooleBundle\SwooleBundle\Coroutine\Watchdog\CoroutineWatchdog;
$received = [];
$owners = [];
Swoole\Coroutine\run(static function () use (&$received, &$owners): void {
    $queue = new SerialQueue(new Adapter(), static function (mixed $payload) use (&$received, &$owners): void {
        $received[] = $payload;
        $owners[] = Swoole\Coroutine::getCid();
    }, 1, static function (): void {}, 0.1);
    $watchdog = new CoroutineWatchdog(1, 1);
    for ($payload = 0; $payload < 6; ++$payload) {
        $queue->submit($payload);
        Swoole\Coroutine::sleep(0.005);
        if (!$queue->isEmpty()) { exit(1); }
        $watchdog->check();
    }
    $queue->drain();
});
if ($received !== [0, 1, 2, 3, 4, 5] || count(array_unique($owners)) !== 1) { exit(2); }
echo $owners[0];
CHILD;
        $process = $this->probe($script);
        self::assertNotContains((int) $process->getOutput(), $this->stalledCoroutineIds($process));
    }

    public function testChannelWaitInsideSerialQueueCallbackIsReportedAsStalled(): void
    {
        $script = <<<'CHILD'
require $argv[1];
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Component\Concurrency\SerialQueue;
use SwooleBundle\SwooleBundle\Coroutine\Watchdog\CoroutineWatchdog;
$owner = null;
Swoole\Coroutine\run(static function () use (&$owner): void {
    $blocked = new Swoole\Coroutine\Channel(1);
    $queue = new SerialQueue(new Adapter(), static function (mixed $payload) use ($blocked, &$owner): void {
        $owner = Swoole\Coroutine::getCid();
        $blocked->pop();
    }, 1, static function (): void {}, 0.01);
    $queue->submit('blocked');
    $watchdog = new CoroutineWatchdog(1, 1);
    try {
        for ($check = 0; $check < 3; ++$check) {
            Swoole\Coroutine::sleep(0.005);
            $watchdog->check();
        }
    } finally {
        $blocked->push(true);
        $queue->drain();
    }
});
if ($owner === null) { exit(1); }
echo $owner;
CHILD;
        $process = $this->probe($script);
        self::assertContains((int) $process->getOutput(), $this->stalledCoroutineIds($process));
    }

    /** @return list<int> */
    private function stalledCoroutineIds(Process $process): array
    {
        $ids = [];
        foreach (explode("\n", $process->getErrorOutput()) as $line) {
            if (preg_match('/WARNING: Coroutine stall confirmed (\{.*\})$/', $line, $matches) !== 1) {
                continue;
            }
            $context = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
            $ids[] = $context['coroutine_id'];
        }

        return $ids;
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
