<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Health;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the monitor's real Swoole timer in a child process: every tick runs a full
 * health evaluation in a coroutine the swoole bundle does not manage, so the tick
 * must give back the pooled services that coroutine took.
 */
final class HealthMonitorTimerProbeTest extends TestCase
{
    public function testEachTickReleasesThePooledServicesItsCoroutineTook(): void
    {
        self::assertTrue(extension_loaded('swoole'), 'The monitor timer requires Swoole.');
        $script = <<<'PHP'
require $argv[1];
final class RecordingPool implements SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool {
    /** @var list<int> */
    public array $released = [];
    public function get(): object { return new stdClass(); }
    public function releaseFromCoroutine(int $cId): void { $this->released[] = $cId; }
    public function getAssignedCount(): int { return 0; }
    public function getFreeCount(): int { return 0; }
    public function getInstancesLimit(): int { return 1; }
}
// Each evaluation reads the clock once, from the tick's coroutine.
final class TickClock implements Psr\Clock\ClockInterface {
    /** @var list<int> */
    public array $ticks = [];
    public ?Closure $afterRead = null;
    public function now(): DateTimeImmutable {
        $this->ticks[] = Swoole\Coroutine::getCid();
        ($this->afterRead)?->__invoke(count($this->ticks));
        return new DateTimeImmutable('2026-10-10T12:00:00Z');
    }
}
final class RecordingAlerts implements App\Shared\Application\Port\AdminAlertPortInterface {
    /** @var list<string> */
    public array $titles = [];
    public function alertAdmins(string $title, string $body, string $eventType, ?array $referenceData = null): void { $this->titles[] = $title; }
}
final class AlertsOn implements App\Shared\Application\Port\SystemSettingsPortInterface {
    public function get(string $key): bool|int|string { return true; }
}
$pool = new RecordingPool();
$clock = new TickClock();
$alerts = new RecordingAlerts();
$redis = new App\Shared\Infrastructure\Redis\RedisClientFactory(
    'redis://redis.baander.app:6379',
    connectionFactory: static fn (): Redis => throw new RedisException('Connection refused'),
);
$checks = new App\Shared\Infrastructure\Health\HealthCheckService(
    connection: Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
    redisClientFactory: $redis,
    appEnv: 'prod',
    appSecret: 'probe-secret',
    appUrl: 'https://baander.app',
    oauthPrivateKeyPath: '',
    oauthPublicKeyPath: '',
    vapidPublicKey: '',
    vapidPrivateKey: '',
    messengerWorkerHealth: new App\Shared\Infrastructure\Health\MessengerWorkerHealth($redis, new Symfony\Component\Clock\MockClock(), new App\Shared\Infrastructure\Health\HealthAlertTable()),
);
$service = new App\Shared\Infrastructure\Health\HealthAlertService(
    $checks, $alerts, new AlertsOn(), new App\Shared\Infrastructure\Health\HealthAlertTable(), $clock, new Psr\Log\NullLogger(),
);
$monitor = new App\Shared\Infrastructure\Health\HealthMonitorSubscriber(
    $service,
    new Psr\Log\NullLogger(),
    1,
    new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper(
        new SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer([0 => [$pool]]),
        new SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole(),
    ),
);
// A constructed Swoole\Server keeps Event::wait() from running the reactor; the
// events only need an HTTP worker's server object.
$server = (new ReflectionClass(Swoole\Server::class))->newInstanceWithoutConstructor();
$watchdog = Swoole\Timer::after(4000, static function (): void {
    fwrite(STDERR, "Timer did not terminate.\n");
    exit(1);
});
$clock->afterRead = static function (int $ticks) use ($monitor, $server, $watchdog): void {
    if ($ticks === 2) {
        $monitor->onWorkerStopped(new SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent($server, 0));
        Swoole\Timer::clear($watchdog);
    }
};
$monitor->onWorkerStarted(new SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent($server, 0));
Swoole\Event::wait();
$ticks = $clock->ticks;
if (count($ticks) !== 2 || min($ticks) < 1 || count(array_unique($ticks)) !== 2 || $pool->released !== $ticks) {
    fwrite(STDERR, sprintf("Ticks ran in coroutines %s; released %s.\n", json_encode($ticks), json_encode($pool->released)));
    exit(2);
}
if ($alerts->titles !== ['redis health degraded']) {
    fwrite(STDERR, sprintf("Unexpected alerts %s.\n", json_encode($alerts->titles)));
    exit(3);
}
echo "two ticks, each released its coroutine, one alert\n";
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 5) . '/vendor/autoload.php']);
        $process->setTimeout(8);

        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());
        self::assertSame("two ticks, each released its coroutine, one alert\n", $process->getOutput());
    }
}
