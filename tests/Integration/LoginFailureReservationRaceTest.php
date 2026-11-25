<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Concurrent wrong passwords for one account against the per-account failure limit. Each child
 * process is one sign-in that charges the limit as the password authenticator does; all of them
 * read the limiter before any writes, behind a file barrier, over the disposable Redis.
 */
final class LoginFailureReservationRaceTest extends TestCase
{
    public const string EMAIL = 'race@baander.app';
    private const int LIMIT = 10;
    private const int SIGN_INS = 50;

    private string $directory;
    private string $name;
    private string $dsn;
    /** @var list<Process> */
    private array $children = [];

    /** @return array{id: string, policy: string, limit: int, interval: string} the shipped policy of auth_login_email_failures, under its own name */
    public static function config(string $name): array
    {
        return ['id' => $name, 'policy' => 'sliding_window', 'limit' => self::LIMIT, 'interval' => '300 seconds'];
    }

    protected function setUp(): void
    {
        $dsn = getenv('REDIS_URL');
        self::assertIsString($dsn, 'Run this test with the disposable Redis runner.');
        self::assertNotSame('', $dsn);
        $this->dsn = $dsn;
        $this->name = 'login-failure-race-' . bin2hex(random_bytes(12));
        $this->directory = sys_get_temp_dir() . '/baander-login-race-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if ($child->isRunning()) {
                $child->stop(0);
            }
        }
        // Only this test's keys are removed; never scan or flush the service.
        $namespace = RedisRateLimiterStore::namespace($this->name);
        $keys = [
            $namespace . ':generation',
            $namespace . ':subject:' . hash('sha256', serialize(self::config($this->name)) . "\0" . self::EMAIL),
        ];
        $pool = new RedisClientFactory($this->dsn);
        try {
            $pool->borrow(static function (\Redis $redis) use ($keys): void {
                $redis->del(...$keys);
            });
        } finally {
            $pool->dispose();
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testFiftyConcurrentWrongPasswordsRunAtMostTenPasswordChecks(): void
    {
        $signIns = array_map($this->child(...), range(1, self::SIGN_INS));
        array_walk($signIns, static fn (Process $child) => $child->start());
        $this->awaitTheBarrier($signIns);
        file_put_contents($this->directory . '/go', 'go');

        $results = array_map($this->finish(...), $signIns);

        $checks = count(array_filter($results, static fn (array $result): bool => $result['checked']));
        self::assertGreaterThan(0, $checks);
        self::assertLessThanOrEqual(self::LIMIT, $checks, json_encode($results, JSON_THROW_ON_ERROR));
    }

    /** @param list<Process> $children */
    private function awaitTheBarrier(array $children): void
    {
        $deadline = hrtime(true) + 20_000_000_000;
        while (count(glob($this->directory . '/*.ready') ?: []) < count($children)) {
            foreach ($children as $child) {
                $child->checkTimeout();
                if (!$child->isRunning()) {
                    self::fail('A sign-in exited before the barrier: ' . $child->getErrorOutput());
                }
            }
            if (hrtime(true) >= $deadline) {
                self::fail('The sign-ins did not reach the barrier.');
            }
            usleep(10000);
        }
    }

    private function child(int $participant): Process
    {
        $child = new Process([
            PHP_BINARY, dirname(__DIR__) . '/Fixtures/Auth/login-failure-reservation.php',
            $this->name, $this->directory, (string) $participant,
        ], env: ['REDIS_URL' => $this->dsn], timeout: 40);
        $this->children[] = $child;

        return $child;
    }

    /** @return array{checked: bool, unavailable: bool} */
    private function finish(Process $child): array
    {
        self::assertSame(0, $child->wait(), $child->getErrorOutput());
        self::assertSame('', $child->getErrorOutput());
        $result = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }
}
