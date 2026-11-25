<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Service;

use App\Shared\Application\Actor;
use App\Shared\Application\Service\RateLimiterClearer;
use Baander\RedisRateLimiter\Administration\RateLimiterAdministrationInterface;
use Baander\RedisRateLimiter\Exception\RateLimiterClearFailedException;
use Baander\RedisRateLimiter\Exception\UnknownRateLimiterException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RateLimiterClearerTest extends TestCase
{
    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testClearingOneLimiterLogsTheActorAndTheLimiter(): void
    {
        $administration = $this->createMock(RateLimiterAdministrationInterface::class);
        $administration->expects($this->once())->method('clear')->with('auth_login_ip');

        $this->clearer($administration)->clear('sa@baander.app', 'auth_login_ip');

        self::assertSame(
            [[Level::Notice, 'Rate limiter state cleared.', ['actor' => 'sa@baander.app', 'limiters' => ['auth_login_ip']]]],
            $this->records(),
        );
    }

    public function testClearingEveryLimiterLogsTheActorAndEveryLimiter(): void
    {
        $administration = $this->createStub(RateLimiterAdministrationInterface::class);
        $administration->method('clearAll')->willReturn(['auth_login_ip', 'lrclib']);

        $cleared = $this->clearer($administration)->clearAll(Actor::CLI);

        self::assertSame(['auth_login_ip', 'lrclib'], $cleared);
        self::assertSame(
            [[Level::Notice, 'Rate limiter state cleared.', ['actor' => Actor::CLI, 'limiters' => ['auth_login_ip', 'lrclib']]]],
            $this->records(),
        );
    }

    /** The limiters the failure does not name stay cleared, so the attempt is logged with its actor. */
    public function testAClearOfEveryLimiterThatFailsPartwayIsLoggedWithTheActor(): void
    {
        $failure = RateLimiterClearFailedException::forLimiters(['lrclib']);
        $administration = $this->createStub(RateLimiterAdministrationInterface::class);
        $administration->method('clearAll')->willThrowException($failure);

        try {
            $this->clearer($administration)->clearAll('sa@baander.app');
            self::fail('The failure must reach the caller.');
        } catch (RateLimiterClearFailedException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(
            [[Level::Warning, 'Clearing every rate limiter left some limiters uncleared.', ['actor' => 'sa@baander.app', 'error' => $failure->getMessage()]]],
            $this->records(),
        );
    }

    public function testAnUnknownLimiterIsNotLoggedAsCleared(): void
    {
        $administration = $this->createStub(RateLimiterAdministrationInterface::class);
        $administration->method('clear')->willThrowException(UnknownRateLimiterException::named('nope', ['auth_login_ip']));

        $this->expectException(UnknownRateLimiterException::class);

        try {
            $this->clearer($administration)->clear('sa@baander.app', 'nope');
        } finally {
            self::assertSame([], $this->records());
        }
    }

    private function clearer(RateLimiterAdministrationInterface $administration): RateLimiterClearer
    {
        return new RateLimiterClearer($administration, new Logger('test', [$this->logs]));
    }

    /** @return list<array{Level, string, array<mixed>}> */
    private function records(): array
    {
        return array_map(
            static fn (LogRecord $record): array => [$record->level, $record->message, $record->context],
            $this->logs->getRecords(),
        );
    }
}
