<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class HmrTimerLifecycleTest extends TestCase
{
    public function testExitCancelsPollAndPendingReloadBeforeContinuingExitChain(): void
    {
        $script = <<<'CHILD'
require $argv[1];
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Server\Runtime\HMR\HotModuleReloader;
use SwooleBundle\SwooleBundle\Server\Runtime\HMR\HotModuleReloadTimer;
use SwooleBundle\SwooleBundle\Server\WorkerHandler\HMRWorkerStartHandler;
use SwooleBundle\SwooleBundle\Server\WorkerHandler\HMRWorkerExitHandler;
use SwooleBundle\SwooleBundle\Server\WorkerHandler\WorkerExitHandler;
$timer = new HotModuleReloadTimer(new Adapter());
$state = (object) ['polls' => 0, 'reloads' => 0, 'exits' => 0, 'ownedIds' => [], 'remainingIds' => [], 'ownedTimersCleared' => false];
$hmr = new class($state, $timer) implements HotModuleReloader {
    public function __construct(private object $state, private HotModuleReloadTimer $timer) {}
    public function tick(Swoole\Server $server): void {
        $this->state->polls++;
        $this->timer->after(500, function (): void { $this->state->reloads++; });
    }
};
$decorated = new class($state) implements WorkerExitHandler {
    public function __construct(private object $state) {}
    public function handle(Swoole\Server $server, int $id): void {
        $this->state->exits++;
        $this->state->remainingIds = iterator_to_array(Swoole\Timer::list());
        $this->state->ownedTimersCleared = array_all($this->state->ownedIds, static fn(int $id): bool => !Swoole\Timer::exists($id));
    }
};
$start = new HMRWorkerStartHandler($hmr, $timer, 5);
$exit = new HMRWorkerExitHandler($timer, $decorated);
$server = new Swoole\Server('127.0.0.1', 0);
Swoole\Coroutine\run(static function () use ($start, $exit, $server, $state, $timer): void {
    // Timer stats also count internal coroutine sleep timers; compare actual user timer IDs.
    $baselineIds = iterator_to_array(Swoole\Timer::list());
    $start->handle($server, 0);
    Swoole\Coroutine::sleep(0.02);
    $state->ownedIds = array_values(array_diff(iterator_to_array(Swoole\Timer::list()), $baselineIds));
    if ($state->polls < 1 || count($state->ownedIds) < 2) { exit(1); }
    $exit->handle($server, 0);
    $exit->handle($server, 0);
    $polls = $state->polls;
    if ($timer->after(1, static function (): void { exit(2); })) { exit(3); }
    Swoole\Coroutine::sleep(0.55);
    if ($state->polls !== $polls || $state->reloads !== 0 || $state->exits !== 2 || $state->remainingIds !== $baselineIds || !$state->ownedTimersCleared) {
        fwrite(STDERR, json_encode($state)); exit(4);
    }
});
echo "poll and pending reload cancelled before exit chain\n";
CHILD;
        $this->assertProbe($script, "poll and pending reload cancelled before exit chain\n");
    }

    public function testSlowPollDoesNotOverlapAndCanRestartAfterStop(): void
    {
        $script = <<<'CHILD'
require $argv[1];
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Server\Runtime\HMR\HotModuleReloadTimer;
$timer = new HotModuleReloadTimer(new Adapter());
$state = (object) ['active' => 0, 'peak' => 0, 'calls' => 0, 'delivered' => 0];
Swoole\Coroutine\run(static function () use ($timer, $state): void {
    // Timer stats also count internal coroutine sleep timers; compare actual user timer IDs.
    $baselineIds = iterator_to_array(Swoole\Timer::list());
    $timer->start(2, static function () use ($state): void {
        $state->calls++;
        $state->active++;
        $state->peak = max($state->peak, $state->active);
        Swoole\Coroutine::sleep(0.015);
        $state->active--;
    });
    Swoole\Coroutine::sleep(0.06);
    $pollIds = array_values(array_diff(iterator_to_array(Swoole\Timer::list()), $baselineIds));
    if (count($pollIds) !== 1) { exit(3); }
    $timer->stop();
    if (!array_all($pollIds, static fn(int $id): bool => !Swoole\Timer::exists($id))) { exit(4); }
    Swoole\Coroutine::sleep(0.025);
    if ($state->peak !== 1 || $state->calls < 2 || $state->active !== 0) { exit(1); }
    $timer->start(100, static function (): void {});
    $timer->after(2, static function () use ($state): void { $state->delivered++; });
    $restartIds = array_values(array_diff(iterator_to_array(Swoole\Timer::list()), $baselineIds));
    if (count($restartIds) !== 2) { exit(5); }
    Swoole\Coroutine::sleep(0.01);
    $timer->stop();
    if ($state->delivered !== 1 || iterator_to_array(Swoole\Timer::list()) !== $baselineIds
        || !array_all($restartIds, static fn(int $id): bool => !Swoole\Timer::exists($id))) { exit(2); }
});
echo "slow polls serialized; completed reload and restart cleaned up\n";
CHILD;
        $this->assertProbe($script, "slow polls serialized; completed reload and restart cleaned up\n");
    }

    private function assertProbe(string $script, string $output): void
    {
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(5);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame($output, $process->getOutput());
        self::assertSame('', $process->getErrorOutput());
    }
}
