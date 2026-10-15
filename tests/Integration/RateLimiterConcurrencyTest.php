<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Application\Exception\RateLimiterUnavailableException;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterStore;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Tests\Fixtures\RateLimiter\RateLimiterFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Independent clients share only the disposable Redis and a file start barrier. */
final class RateLimiterConcurrencyTest extends TestCase
{
    private string $directory;
    private string $name;
    private string $dsn;
    /** @var list<Process> */
    private array $children = [];

    protected function setUp(): void
    {
        $dsn = getenv('REDIS_URL');
        self::assertIsString($dsn, 'Run this acceptance test with the disposable Redis runner.');
        self::assertNotSame('', $dsn);
        $this->dsn = $dsn;
        $this->name = 'acceptance-' . bin2hex(random_bytes(12));
        $this->directory = sys_get_temp_dir() . '/baander-limiter-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if ($child->isRunning()) {
                $child->stop(0);
            }
        }
        // Only this test's namespaces are removed; never scan or flush the service.
        $pool = new RedisClientFactory($this->dsn);
        try {
            foreach ([$this->name, $this->name . '-other'] as $name) {
                $keys = RateLimiterFixture::keys($name);
                $pool->borrow(static function (\Redis $redis) use ($keys): void {
                    $redis->del(...$keys);
                });
            }
        } finally {
            $pool->dispose();
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function policies(): iterable
    {
        yield 'fixed window' => ['fixed_window'];
        yield 'sliding window' => ['sliding_window'];
    }

    #[DataProvider('policies')]
    public function testConcurrentProcessesAdmitExactlyTheLastToken(string $policy): void
    {
        self::assertTrue($this->runConsumer($policy)['accepted']);
        $consumers = [];
        for ($i = 0; $i < 6; ++$i) {
            $child = $this->child($policy, barrier: $this->directory, participant: (string) $i);
            $child->start();
            $consumers[] = $child;
        }
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            foreach ($consumers as $child) {
                $child->checkTimeout();
                if (!$child->isRunning()) {
                    self::fail('Consumer exited before the barrier: ' . $child->getErrorOutput());
                }
            }
            if (count(glob($this->directory . '/*.ready') ?: []) === count($consumers)) {
                break;
            }
            if (hrtime(true) >= $deadline) {
                self::fail('Children did not reach the barrier.');
            }
            usleep(10000);
        } while (true);
        file_put_contents($this->directory . '/go', 'go');
        $accepted = 0;
        foreach ($consumers as $child) {
            $result = $this->finish($child);
            self::assertArrayNotHasKey('unavailable', $result, 'Contention must resolve within the retry budget.');
            $accepted += (int) $result['accepted'];
        }
        self::assertSame(1, $accepted);
        self::assertFalse($this->runConsumer($policy)['accepted'], 'A fresh process observes the exhausted budget.');
    }

    #[DataProvider('policies')]
    public function testFreshProcessesObserveResetsAndKeepSubjectsAndNamespacesSeparate(string $policy): void
    {
        self::assertTrue($this->runConsumer($policy)['accepted']);
        self::assertTrue($this->runConsumer($policy)['accepted']);
        self::assertFalse($this->runConsumer($policy)['accepted']);
        self::assertTrue($this->runConsumer($policy, 'other')['accepted']);
        self::assertTrue($this->runConsumer($policy, name: $this->name . '-other')['accepted']);
        self::assertTrue($this->runConsumer($policy, name: $this->name . '-other')['accepted']);
        self::assertTrue($this->runConsumer($policy, action: 'reset')['reset']);
        self::assertTrue($this->runConsumer($policy)['accepted']);
        self::assertTrue($this->runConsumer($policy)['accepted']);
        self::assertFalse($this->runConsumer($policy)['accepted']);
        self::assertFalse($this->runConsumer($policy, name: $this->name . '-other')['accepted']);
        self::assertTrue($this->runConsumer($policy, action: 'clear')['cleared']);
        self::assertTrue($this->runConsumer($policy)['accepted']);
        self::assertTrue($this->runConsumer($policy, 'other')['accepted']);
        self::assertTrue($this->runConsumer($policy, 'other')['accepted'], 'Named clear resets every subject.');
        self::assertFalse($this->runConsumer($policy, name: $this->name . '-other')['accepted'], 'Named clear preserves other namespaces.');
    }

    public function testRefusedRedisFailsClosedWithinTheRequestBound(): void
    {
        $child = $this->child('fixed_window', dsn: 'redis://127.0.0.1:1');
        $child->start();
        $result = $this->finish($child);
        self::assertTrue($result['unavailable']);
        self::assertLessThan(5.0, $result['elapsed']);
        self::assertArrayNotHasKey('accepted', $result);
    }

    public function testRedisRejectsWritersThatPrecedeAResetOrNamedClear(): void
    {
        $pool = new RedisClientFactory($this->dsn);
        $store = new RedisRateLimiterStore($pool);
        $subject = hash('sha256', 'subject');
        try {
            $beforeReset = $store->read($this->name, $subject);
            self::assertSame('', $beforeReset->payload);
            $store->reset($this->name, $subject);
            self::assertFalse($store->commit($beforeReset, 'old state', 60));
            $beforeClear = $store->read($this->name, $subject);
            $store->clear($this->name);
            self::assertFalse($store->commit($beforeClear, 'old state', 60));
            self::assertTrue($this->runConsumer('fixed_window')['accepted']);
        } finally {
            $pool->dispose();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function repairs(): iterable
    {
        yield 'subject reset' => ['reset'];
        yield 'named clear' => ['clear'];
    }

    #[DataProvider('repairs')]
    public function testResetRepairsACorruptHashWithoutRetainingUnexpectedFields(string $action): void
    {
        self::assertTrue($this->runConsumer('fixed_window')['accepted']);
        self::assertTrue($this->runConsumer('fixed_window')['accepted']);
        self::assertFalse($this->runConsumer('fixed_window')['accepted']);
        $pool = new RedisClientFactory($this->dsn);
        $key = RedisRateLimiterStore::namespace($this->name) . ':subject:' . RateLimiterFixture::subjectDigest($this->name, 'fixed_window', 'subject');
        try {
            $pool->borrow(static function (\Redis $redis) use ($key): void {
                $redis->hSet($key, 'unexpected', 'corrupt');
            });
            $unavailable = $this->runConsumer('fixed_window');
            self::assertTrue($unavailable['unavailable']);
            self::assertArrayNotHasKey('accepted', $unavailable);
            $this->runConsumer('fixed_window', action: $action);
            self::assertTrue($this->runConsumer('fixed_window')['accepted']);
            self::assertTrue($this->runConsumer('fixed_window')['accepted']);
            self::assertFalse($this->runConsumer('fixed_window')['accepted']);
            self::assertEqualsCanonicalizing(['generation', 'revision', 'payload'], $pool->borrow(
                static fn(\Redis $redis): array => $redis->hKeys($key),
            ));
        } finally {
            $pool->dispose();
        }
    }

    public function testAnExpiredEmptyTombstoneCannotAcceptAnOldWriter(): void
    {
        $pool = new RedisClientFactory($this->dsn);
        $store = new RedisRateLimiterStore($pool);
        $subject = hash('sha256', 'subject');
        try {
            $old = $store->read($this->name, $subject);
            // Outlast the real ten-second tombstone, as a paused process could.
            usleep(10_100_000);
            $fresh = $store->read($this->name, $subject);
            self::assertNotSame($old->revision, $fresh->revision);
            try {
                $store->commit($old, 'old state', 60);
                self::fail('An expired snapshot must fail closed.');
            } catch (RateLimiterUnavailableException) {
                self::assertSame('', $store->read($this->name, $subject)->payload);
            }
            self::assertTrue($this->runConsumer('fixed_window')['accepted']);
        } finally {
            $pool->dispose();
        }
    }

    public function testPausedRedisFailsClosedAndRecoversInANewProcess(): void
    {
        $pool = new RedisClientFactory($this->dsn);
        $control = $pool->createStandaloneConnection();
        $control->setOption(\Redis::OPT_READ_TIMEOUT, 8.0);
        try {
            self::assertNotFalse($control->rawCommand('CLIENT', 'PAUSE', '7000', 'ALL'));
            $child = $this->child('fixed_window');
            $child->start();
            $result = $this->finish($child);
            self::assertTrue($result['unavailable']);
            self::assertLessThan(5.0, $result['elapsed']);
            self::assertArrayNotHasKey('accepted', $result);
        } finally {
            $control->rawCommand('CLIENT', 'UNPAUSE');
            $control->close();
            $pool->dispose();
        }
        self::assertTrue($this->runConsumer('fixed_window')['accepted']);
    }

    /** @return array<string, mixed> */
    private function runConsumer(string $policy, string $subject = 'subject', string $action = 'consume', ?string $name = null): array
    {
        $child = $this->child($policy, $subject, $action, $name);
        $child->start();

        return $this->finish($child);
    }

    private function child(string $policy, string $subject = 'subject', string $action = 'consume', ?string $name = null, string $barrier = '-', string $participant = '-', ?string $dsn = null): Process
    {
        $child = new Process([
            PHP_BINARY, dirname(__DIR__) . '/Fixtures/RateLimiter/consume.php',
            $name ?? $this->name, $policy, $subject, $action, $barrier, $participant,
        ], env: ['REDIS_URL' => $dsn ?? $this->dsn], timeout: 15);
        $this->children[] = $child;

        return $child;
    }

    /** @return array<string, mixed> */
    private function finish(Process $child): array
    {
        self::assertSame(0, $child->wait(), $child->getErrorOutput());
        self::assertSame('', $child->getErrorOutput());
        $result = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }
}
