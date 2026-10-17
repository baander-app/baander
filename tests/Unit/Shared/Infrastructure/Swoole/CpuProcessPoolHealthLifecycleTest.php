<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CpuProcessPoolHealthLifecycleTest extends TestCase
{
    public function testWebLifecycleStartsWithoutExecutorProcessesOrTimers(): void
    {
        $directory = sys_get_temp_dir() . '/baander-web-worker-lifecycle-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $fixture = <<<'PHP'
require $argv[1];
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventBuffer;
use App\Shared\Infrastructure\Swoole\SwooleWorkerEventSubscriber;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\EventDispatcher\EventDispatchingServerStartHandler;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\EventDispatcher\EventDispatchingWorkerStartHandler;
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
$probe = new Swoole\Table(16);
$probe->column('error', Swoole\Table::TYPE_STRING, 2048);
$probe->create();
$dispatcher = new Symfony\Component\EventDispatcher\EventDispatcher();
$dispatcher->addSubscriber(new SwooleWorkerEventSubscriber(new SwooleWorkerEventBuffer()));
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
$server->on('workerStart', static function (Swoole\Server $server, int $workerId) use ($workerStarted, $probe, $owner): void {
    trackPid(getmypid());
    try {
        check(getmypid() !== $owner, 'HTTP worker did not fork from web master');
        $workerStarted->handle($server, $workerId);
        check(Swoole\Timer::stats()['num'] === 0, 'HTTP worker startup scheduled an executor timer');
        $probe->set('worker', ['error' => '']);
    } catch (Throwable $error) {
        $probe->set('worker', ['error' => $error->getMessage()]);
    }
});
$server->on('start', static function (Swoole\Server $server) use ($serverStarted, $probe, $owner): void {
    trackPid($server->manager_pid);
    try {
        check(getmypid() === $owner, 'ServerStartedEvent ran outside the web master');
        $timers = iterator_to_array(Swoole\Timer::list());
        $serverStarted->handle($server);
        check(iterator_to_array(Swoole\Timer::list()) === $timers, 'Web startup scheduled an executor timer');
        $probe->set('owner', ['error' => '']);
    } catch (Throwable $error) {
        $probe->set('owner', ['error' => $error->getMessage()]);
    }
    $deadline = hrtime(true) + 3_000_000_000;
    Swoole\Timer::tick(10, static function (int $timer) use ($probe, $server, $deadline): void {
        if (!$probe->exists('worker') && hrtime(true) < $deadline) { return; }
        Swoole\Timer::clear($timer);
        $server->shutdown();
    });
});
check($server->start(), 'Native process-mode server failed to start');
foreach (['owner', 'worker'] as $stage) {
    $result = $probe->get($stage);
    check(is_array($result), 'Lifecycle callback never completed: ' . $stage);
    check($result['error'] === '', $stage . ': ' . $result['error']);
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
