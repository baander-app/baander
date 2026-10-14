<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use Fiber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/** Reproduces current limitations; these are evidence of gaps, not guarantees of protection. */
final class RateLimiterFailureSemanticsTest extends TestCase
{
    public function testCacheFailuresCurrentlyPermitRequestsBeyondTheLimit(): void
    {
        $cache = new class extends AbstractAdapter {
            public function __construct()
            {
                parent::__construct();
            }

            /** @param list<string> $ids
             * @return iterable<string, mixed>
             */
            protected function doFetch(array $ids): iterable
            {
                throw new \RuntimeException('Disposable Redis read failure.');
            }

            protected function doHave(string $id): bool
            {
                return false;
            }

            protected function doClear(string $namespace): bool
            {
                return false;
            }

            /** @param list<string> $ids */
            protected function doDelete(array $ids): bool
            {
                return false;
            }

            /** @param array<string, mixed> $values
             * @return list<string>|bool
             */
            protected function doSave(array $values, int $lifetime): array|bool
            {
                return false;
            }
        };
        $factory = new RateLimiterFactory(['id' => 'failure', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '60 seconds'], new CacheStorage($cache));

        self::assertTrue($factory->create('192.0.2.20')->consume()->isAccepted());
        self::assertTrue($factory->create('192.0.2.20')->consume()->isAccepted(), 'Failed cache reads and writes currently lose the budget.');
    }

    public function testUnlockedConcurrentConsumersBothAcceptTheLastToken(): void
    {
        $storage = new class implements StorageInterface {
            private InMemoryStorage $inner;

            public function __construct()
            {
                $this->inner = new InMemoryStorage();
            }

            public function fetch(string $limiterStateId): ?LimiterStateInterface
            {
                $state = $this->inner->fetch($limiterStateId);
                Fiber::suspend();

                return $state;
            }

            public function save(LimiterStateInterface $limiterState): void
            {
                $this->inner->save($limiterState);
            }

            public function delete(string $limiterStateId): void
            {
                $this->inner->delete($limiterStateId);
            }
        };
        $factory = new RateLimiterFactory(['id' => 'concurrency', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '60 seconds'], $storage);
        $first = new Fiber(fn () => $factory->create('192.0.2.20')->consume());
        $second = new Fiber(fn () => $factory->create('192.0.2.20')->consume());
        $first->start();
        $second->start();
        $first->resume();
        $second->resume();

        self::assertTrue($first->getReturn()->isAccepted());
        self::assertTrue($second->getReturn()->isAccepted(), 'Without a shared lock, two consumers read the same budget.');
    }
}
