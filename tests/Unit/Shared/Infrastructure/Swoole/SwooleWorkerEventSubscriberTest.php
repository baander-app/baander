<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventBuffer;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventSubscriber;
use App\Shared\Infrastructure\Swoole\WebSocketConnectionRegistry;
use App\Shared\Infrastructure\Swoole\WebSocketPusher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Server;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SwooleWorkerEventSubscriberTest extends TestCase
{
    public function testStartupRecordsTheEventAndInitializesWebSocketServices(): void
    {
        $buffer = new SwooleWorkerEventBuffer();
        $registry = WebSocketConnectionRegistry::create(16, 16);
        $pusher = new WebSocketPusher($registry, new JsonEncoder());
        $subscriber = new SwooleWorkerEventSubscriber($buffer, webSocketPusher: $pusher, webSocketRegistry: $registry);
        $server = new Server('127.0.0.1', 0);
        $server->worker_id = 3;

        $subscriber->onWorkerStarted(new WorkerStartedEvent($server, 3));

        self::assertSame(3, $registry->getWorkerId());
        self::assertSame($server, (new \ReflectionProperty($pusher, 'server'))->getValue($pusher));
        self::assertSame(['started'], array_column($buffer->getAll(), 'type'));
        self::assertSame([3], array_column($buffer->getAll(), 'workerId'));
    }

    public function testWorkerStopDisposesTheRedisFactoryAndRecordsTheEvent(): void
    {
        $buffer = new SwooleWorkerEventBuffer();
        $factory = $this->createMock(RedisClientFactory::class);
        $factory->expects(self::once())->method('dispose');
        $subscriber = new SwooleWorkerEventSubscriber($buffer, redisClientFactory: $factory);

        $subscriber->onWorkerStopped(new WorkerStoppedEvent(new Server('127.0.0.1', 0), 3));

        self::assertSame(['stopped'], array_column($buffer->getAll(), 'type'));
        self::assertSame([3], array_column($buffer->getAll(), 'workerId'));
    }

    public function testWorkerStopWorksWithoutTheOptionalRedisFactory(): void
    {
        $buffer = new SwooleWorkerEventBuffer();
        $subscriber = new SwooleWorkerEventSubscriber($buffer);

        $subscriber->onWorkerStopped(new WorkerStoppedEvent(new Server('127.0.0.1', 0), 0));

        self::assertSame(['stopped'], array_column($buffer->getAll(), 'type'));
    }

    public function testSigtermStopsTheServerWhileThePoolHealthCheckRuns(): void
    {
        $directory = sys_get_temp_dir() . '/baander-subscriber-sigterm-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        // Swoole keeps the master's loop running after SIGTERM while any timer is left in it, so
        // without the BeforeShutdown hook start() never returns and the process times out.
        $fixture = <<<'PHP'
require $argv[1];
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventBuffer;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventSubscriber;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\EventDispatcher\EventDispatchingServerStartHandler;
final class SigtermProbeWorker implements ProcessPoolWorkerInterface {
    public function supportedTypes(): array { return ['probe']; }
    public function handle(string $payload): string { return 'completed'; }
}
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function trackPid(int $pid): void {
    $stat = file_get_contents('/proc/' . $pid . '/stat');
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    file_put_contents($GLOBALS['argv'][2] . '/pids', $pid . ' ' . $fields[19] . "\n", FILE_APPEND | LOCK_EX);
}
$pool = new CpuProcessPool([new SigtermProbeWorker()], 1, new Psr\Log\NullLogger(), 16, $argv[2]);
$pool->boot();
foreach ((new ReflectionProperty($pool, 'workers'))->getValue($pool) as $worker) { trackPid($worker->pid); }
$locator = new Symfony\Component\DependencyInjection\ServiceLocator([
    CpuProcessPool::class => static fn() => $pool,
]);
$subscriber = new SwooleWorkerEventSubscriber(new SwooleWorkerEventBuffer(), $locator);
$dispatcher = new Symfony\Component\EventDispatcher\EventDispatcher();
$dispatcher->addSubscriber($subscriber);
$serverStarted = new EventDispatchingServerStartHandler($dispatcher);
$server = new Swoole\Http\Server('127.0.0.1', 0, SWOOLE_PROCESS);
$server->set([
    'worker_num' => 1,
    'reactor_num' => 1,
    'log_file' => $argv[2] . '/server.log',
    'max_wait_time' => 1,
]);
$server->on('request', static function ($request, $response): void { $response->end('ready'); });
$subscriber->configure($server);
$timer = null;
$server->on('start', static function (Swoole\Server $server) use ($serverStarted, $pool, &$timer): void {
    trackPid($server->manager_pid);
    $serverStarted->handle($server);
    $timer = (new ReflectionProperty($pool, 'healthTimerId'))->getValue($pool);
    Swoole\Timer::after(200, static function (): void { posix_kill(getmypid(), SIGTERM); });
});
check($server->start(), 'Native process-mode server failed to start');
check(is_int($timer), 'ServerStartedEvent did not schedule health monitoring');
check(!Swoole\Timer::exists($timer), 'Shutdown left the health timer running');
check((new ReflectionProperty($pool, 'healthTimerId'))->getValue($pool) === null, 'Shutdown kept the health timer id');
$pool->shutdown();
PHP;
        $process = new Process([PHP_BINARY, '-r', $fixture, dirname(__DIR__, 5) . '/vendor/autoload.php', $directory]);
        $process->setTimeout(10);

        try {
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            self::assertSame('', $process->getOutput());
            self::assertSame('', $process->getErrorOutput());
        } finally {
            $process->stop(1, SIGKILL);
            foreach (is_file($directory . '/pids') ? file($directory . '/pids', FILE_IGNORE_NEW_LINES) : [] as $entry) {
                [$pid, $start] = explode(' ', $entry);
                $stat = @file_get_contents('/proc/' . $pid . '/stat');
                if ($stat === false) { continue; }
                $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
                if ($fields[19] === $start) { \Swoole\Process::kill((int) $pid, SIGKILL); }
            }
            foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }
}
