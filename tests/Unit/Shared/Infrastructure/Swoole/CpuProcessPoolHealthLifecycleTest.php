<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CpuProcessPoolHealthLifecycleTest extends TestCase
{
    public function testServerStartOwnsTheMonitorAndHttpWorkerStartDoesNotInheritIt(): void
    {
        $directory = sys_get_temp_dir() . '/baander-pool-health-lifecycle-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $fixture = <<<'PHP'
require $argv[1];
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventBuffer;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventSubscriber;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\EventDispatcher\EventDispatchingServerStartHandler;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\EventDispatcher\EventDispatchingWorkerStartHandler;
final class LifecycleProbeWorker implements ProcessPoolWorkerInterface {
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
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$owner = getmypid();
$pool = new CpuProcessPool([new LifecycleProbeWorker()], 1, new Psr\Log\NullLogger(), 16, $argv[2]);
$pool->boot();
$workers = (new ReflectionProperty($pool, 'workers'))->getValue($pool);
foreach ($workers as $worker) { trackPid($worker->pid); }
$probe = new Swoole\Table(16);
$probe->column('error', Swoole\Table::TYPE_STRING, 2048);
$probe->create();
$locator = new Symfony\Component\DependencyInjection\ServiceLocator([
    CpuProcessPool::class => static fn() => $pool,
]);
$dispatcher = new Symfony\Component\EventDispatcher\EventDispatcher();
$dispatcher->addSubscriber(new SwooleWorkerEventSubscriber(new SwooleWorkerEventBuffer(), $locator));
$serverStarted = new EventDispatchingServerStartHandler($dispatcher);
$workerStarted = new EventDispatchingWorkerStartHandler($dispatcher);
$server = new Swoole\Http\Server('127.0.0.1', 0, SWOOLE_PROCESS);
$server->set([
    'worker_num' => 1,
    'reactor_num' => 1,
    'enable_coroutine' => true,
    'log_file' => $argv[2] . '/server.log',
    'max_wait_time' => 1,
]);
$server->on('request', static function ($request, $response): void { $response->end('ready'); });
$server->on('workerStart', static function (Swoole\Server $server, int $workerId) use ($pool, $workerStarted, $probe, $owner): void {
    trackPid(getmypid());
    try {
        check(getmypid() !== $owner, 'HTTP worker did not fork from pool owner');
        $workerStarted->handle($server, $workerId);
        check((new ReflectionProperty($pool, 'healthTimerId'))->getValue($pool) === null, 'HTTP worker startup created a pool monitor');
        try {
            $pool->startHealthCheck();
            throw new RuntimeException('Inherited pool accepted monitor ownership');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'Only the CPU pool owner'), 'Inherited monitor failed for another reason');
        }
        check((new ReflectionProperty($pool, 'healthTimerId'))->getValue($pool) === null, 'Inherited pool allowed a non-owner monitor');
        $probe->set('worker', ['error' => '']);
    } catch (Throwable $error) {
        $probe->set('worker', ['error' => $error->getMessage()]);
    }
});
$server->on('start', static function (Swoole\Server $server) use ($pool, $serverStarted, $probe, $owner): void {
    trackPid($server->manager_pid);
    try {
        check(getmypid() === $owner, 'ServerStartedEvent did not run in CPU pool owner');
        $serverStarted->handle($server);
        $timer = (new ReflectionProperty($pool, 'healthTimerId'))->getValue($pool);
        check(is_int($timer) && Swoole\Timer::exists($timer), 'ServerStartedEvent did not schedule health monitoring');
        check((new ReflectionProperty($pool, 'healthTimerOwnerPid'))->getValue($pool) === $owner, 'Health timer belongs to another process');
        $pool->startHealthCheck();
        check((new ReflectionProperty($pool, 'healthTimerId'))->getValue($pool) === $timer, 'Repeated owner startup replaced the health timer');
        $probe->set('owner', ['error' => '']);
    } catch (Throwable $error) {
        $probe->set('owner', ['error' => $error->getMessage()]);
    }
    $deadline = hrtime(true) + 3_000_000_000;
    Swoole\Timer::tick(10, static function (int $timer) use ($pool, $probe, $server, $deadline): void {
        if (!$probe->exists('worker') && hrtime(true) < $deadline) { return; }
        Swoole\Timer::clear($timer);
        try {
            $pool->shutdown();
            check((new ReflectionProperty($pool, 'healthTimerId'))->getValue($pool) === null, 'Owner shutdown retained its health timer');
            $probe->set('shutdown', ['error' => '']);
        } catch (Throwable $error) {
            $probe->set('shutdown', ['error' => $error->getMessage()]);
        }
        $server->shutdown();
    });
});
check($server->start(), 'Native process-mode server failed to start');
foreach (['owner', 'worker', 'shutdown'] as $stage) {
    $result = $probe->get($stage);
    check(is_array($result), 'Lifecycle callback never completed: ' . $stage);
    check($result['error'] === '', $stage . ': ' . $result['error']);
}
foreach ($workers as $worker) {
    $status = 0;
    check(pcntl_waitpid($worker->pid, $status, WNOHANG) === -1, 'Lifecycle shutdown did not reap CPU worker');
}
$probe->destroy();
PHP;
        $process = new Process([PHP_BINARY, '-r', $fixture, dirname(__DIR__, 5) . '/vendor/autoload.php', $directory]);
        $process->setTimeout(10);

        try {
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
            self::assertSame('', $process->getOutput());
            self::assertSame('', $process->getErrorOutput());
        } finally {
            $process->stop(1, SIGTERM);
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
