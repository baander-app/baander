<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ServicePoolDrainTest extends TestCase
{
    public function testExitPreservesEventChainAndDrainsWithoutOverlappingDestructors(): void
    {
        $script = <<<'CHILD'
require $argv[1];
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\DrainServicePoolsOnWorkerExit;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\UnmanagedFactoryServicePool;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
use SwooleBundle\SwooleBundle\Server\WorkerHandler\WorkerExitHandler;
$state = (object) ['active' => 0, 'peak' => 0, 'destroyed' => 0, 'events' => 0, 'inCoroutine' => true];
$adapter = new Adapter();
$mutex = new class implements Mutex {
    public function acquire(): void {}
    public function release(): void {}
    public function isAcquired(): bool { return false; }
};
$factory = static fn() => new class ($state) {
    public function __construct(private object $state) {}
    public function __destruct() {
        $this->state->active++;
        $this->state->peak = max($this->state->peak, $this->state->active);
        $this->state->inCoroutine = $this->state->inCoroutine && Swoole\Coroutine::getCid() !== -1;
        if (Swoole\Coroutine::getCid() !== -1) {
            Swoole\Coroutine::sleep(0.001);
        }
        $this->state->active--;
        $this->state->destroyed++;
    }
};
$first = new UnmanagedFactoryServicePool($factory, $adapter, $mutex);
$second = new UnmanagedFactoryServicePool($factory, $adapter, $mutex);
$first->get();
$first->releaseFromCoroutine(-1);
$second->get();
$second->releaseFromCoroutine(-1);
$pools = new ServicePoolContainer([0 => [$first, $second]]);
$decorated = new class ($state) implements WorkerExitHandler {
    public function __construct(private object $state) {}
    public function handle(Swoole\Server $worker, int $workerId): void { $this->state->events++; }
};
$handler = new DrainServicePoolsOnWorkerExit($pools, new CoWrapper($pools, $adapter), $adapter, $decorated);
$server = new Swoole\Server('127.0.0.1', 0);
Swoole\Coroutine\run(static function () use ($handler, $server, $first, $adapter): void {
    $handler->handle($server, 0);
    $handler->handle($server, 0);
    Swoole\Coroutine::sleep(0.01);
    // A later callback must drain instances returned since the preceding callback.
    $first->get();
    $first->releaseFromCoroutine($adapter->getCoroutineId());
    $handler->handle($server, 0);
});
if ($state->events !== 3 || $state->destroyed !== 3 || $state->peak !== 1 || !$state->inCoroutine) {
    fwrite(STDERR, json_encode($state)); exit(1);
}
echo "event chain preserved; free instances destroyed in serial live coroutines\n";
CHILD;
        $this->assertProbe($script, "event chain preserved; free instances destroyed in serial live coroutines\n");
    }

    public function testSchedulingFailureIsReportedAndLaterExitCanRetry(): void
    {
        $script = <<<'CHILD'
require $argv[1];
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\DrainServicePoolsOnWorkerExit;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\UnmanagedFactoryServicePool;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole as Adapter;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
$pools = new ServicePoolContainer([]);
$adapter = new Adapter();
$mutex = new class implements Mutex {
    public function acquire(): void {}
    public function release(): void {}
    public function isAcquired(): bool { return false; }
};
$pool = new UnmanagedFactoryServicePool(static fn() => new stdClass(), $adapter, $mutex);
$pools->addPool($pool);
$pool->get();
$pool->releaseFromCoroutine(-1);
$handler = new DrainServicePoolsOnWorkerExit($pools, new CoWrapper($pools, $adapter), $adapter);
$server = new Swoole\Server('127.0.0.1', 0);
Swoole\Coroutine::set(['max_coroutine' => 1]);
set_error_handler(static fn(int $severity, string $message): bool => $severity === E_WARNING && str_contains($message, 'exceed max number of coroutine'));
Swoole\Coroutine\run(static function () use ($handler, $server): void { $handler->handle($server, 0); });
restore_error_handler();
if ($pool->getFreeCount() !== 1) { exit(1); }
Swoole\Coroutine::set(['max_coroutine' => 8]);
Swoole\Coroutine\run(static function () use ($handler, $server): void { $handler->handle($server, 0); });
if ($pool->getFreeCount() !== 0) { exit(2); }
echo "failed scheduling preserved free instances; later exit drained them\n";
CHILD;
        $this->assertProbe($script, "failed scheduling preserved free instances; later exit drained them\n", 'Unable to create coroutine.');
    }

    private function assertProbe(string $script, string $output, ?string $error = null): void
    {
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(5);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame($output, $process->getOutput());
        if ($error !== null) {
            self::assertStringContainsString($error, $process->getErrorOutput());
        }
    }
}
