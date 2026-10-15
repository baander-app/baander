<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimiter;

use App\Shared\Application\Exception\RateLimiterClearFailedException;
use App\Shared\Application\Exception\UnknownRateLimiterException;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterAdministration;
use App\Shared\Infrastructure\RateLimiter\RedisRateLimiterStore;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use App\Shared\Interface\Console\RateLimiterClearCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Redis;
use RedisException;

final class RedisRateLimiterAdministrationTest extends TestCase
{
    public function testClearAllContinuesAfterFailureAndReportsOnlyFailedNames(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::exactly(2))->method('set')->willReturnCallback(static function (string $key): bool {
            if (str_starts_with($key, RedisRateLimiterStore::namespace('broken'))) {
                throw new RedisException('private endpoint and subject');
            }
            return true;
        });
        $administration = $this->administration($redis);
        try {
            $administration->clearAll();
            self::fail('Expected the failed namespace to be reported.');
        } catch (RateLimiterClearFailedException $exception) {
            self::assertStringContainsString('broken', $exception->getMessage());
            self::assertStringNotContainsString('healthy', $exception->getMessage());
            self::assertStringNotContainsString('private', $exception->getMessage());
        }
    }

    public function testRefusedWriteIsNotReportedAsASuccessfulClear(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('set')->willReturn(false);
        $this->expectException(RateLimiterClearFailedException::class);
        $this->administration($redis)->clear('healthy');
    }

    public function testUnknownNameDoesNotTouchRedis(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::never())->method('set');
        $this->expectException(UnknownRateLimiterException::class);
        $this->administration($redis)->clear('unknown');
    }

    /** @return iterable<string, array{array<string, string|bool>, int}> */
    public static function clearCommands(): iterable
    {
        yield 'named' => [['name' => 'healthy', '--json' => true], 1];
        yield 'all' => [['--all' => true, '--json' => true], 2];
    }

    /** @param array<string, string|bool> $arguments */
    #[DataProvider('clearCommands')]
    public function testUnacknowledgedClearMakesTheCommandFailWithoutSuccessData(array $arguments, int $writes): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::exactly($writes))->method('set')->willReturn(false);
        $command = new CommandTester(new RateLimiterClearCommand($this->administration($redis)));
        self::assertSame(Command::FAILURE, $command->execute($arguments, ['capture_stderr_separately' => true]));
        self::assertSame('', $command->getDisplay());
        self::assertStringContainsString('healthy', $command->getErrorOutput());
    }

    private function administration(Redis $redis): RedisRateLimiterAdministration
    {
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn (callable $callback): mixed => $callback($redis));
        $definitions = [];
        foreach (['broken', 'healthy'] as $name) {
            $definitions[$name] = ['policy' => 'fixed_window', 'limit' => 10, 'interval' => '60 seconds', 'description' => null, 'storageNamespace' => RedisRateLimiterStore::namespace($name)];
        }
        return new RedisRateLimiterAdministration($definitions, new RedisRateLimiterStore($factory));
    }
}
