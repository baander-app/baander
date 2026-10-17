<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\NativeDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeDnsResolverTest extends TestCase
{
    private function resolver(): NativeDnsResolver
    {
        return new NativeDnsResolver(dirname(__DIR__, 4) . '/Fixtures/Http/dns-helper.php');
    }

    public function testCompleteReplyPreservesBothFamiliesAndDuplicates(): void
    {
        self::assertSame(['8.8.8.8', '2001:4860:4860::8888', '8.8.8.8'], $this->resolver()->resolve('complete.baander.app', hrtime(true) / 1e9 + 2));
    }

    public function testTimeoutReapsOwnedChildAndAllowsNextLookup(): void
    {
        $started = hrtime(true) / 1e9;
        try {
            $this->resolver()->resolve('slow.baander.app', $started + 0.1);
            self::fail('A stalled child must fail.');
        } catch (\RuntimeException $failure) {
            self::assertSame('DNS lookup deadline exceeded.', $failure->getMessage());
        }
        self::assertLessThan(0.8, hrtime(true) / 1e9 - $started);
        self::assertSame('', trim(file_get_contents('/proc/self/task/' . getmypid() . '/children')));
        self::assertCount(3, $this->resolver()->resolve('complete.baander.app', hrtime(true) / 1e9 + 2));
    }

    #[DataProvider('failedReplies')]
    public function testInvalidRepliesFailClosed(string $hostname): void
    {
        $this->expectException(\RuntimeException::class);
        $this->resolver()->resolve($hostname, hrtime(true) / 1e9 + 2);
    }

    /** @return iterable<array{string}> */
    public static function failedReplies(): iterable
    {
        yield ['malformed.baander.app'];
        yield ['nonzero.baander.app'];
    }

    public function testOversizedCompleteReplyIsRefusedAndReleasesCapacity(): void
    {
        try {
            $this->resolver()->resolve('oversized.baander.app', hrtime(true) / 1e9 + 2);
            self::fail('An oversized address list must fail.');
        } catch (\RuntimeException $failure) {
            self::assertSame('DNS lookup reply exceeded its limit.', $failure->getMessage());
        }
        self::assertSame('', trim(file_get_contents('/proc/self/task/' . getmypid() . '/children')));
        self::assertCount(3, $this->resolver()->resolve('complete.baander.app', hrtime(true) / 1e9 + 2));
    }

    #[DataProvider('hookModes')]
    public function testLifecycleYieldsWithProcessHooksEnabledOrDisabled(bool $hooks): void
    {
        $original = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::setHookFlags($hooks ? SWOOLE_HOOK_ALL : 0);
        try {
            \Swoole\Coroutine\run(function (): void {
                $heartbeats = 0;
                $state = new class { public bool $finished = false; };
                \Swoole\Coroutine::create(static function () use (&$heartbeats, $state): void {
                    while (!$state->finished) {
                        ++$heartbeats;
                        \Swoole\Coroutine::sleep(0.005);
                    }
                });
                try {
                    $this->resolver()->resolve('slow.baander.app', hrtime(true) / 1e9 + 0.1);
                    self::fail('Slow lookup must reach its deadline.');
                } catch (\RuntimeException $failure) {
                    self::assertSame('DNS lookup deadline exceeded.', $failure->getMessage());
                } finally {
                    $state->finished = true;
                }
                self::assertGreaterThan(3, $heartbeats);
                self::assertCount(3, $this->resolver()->resolve('complete.baander.app', hrtime(true) / 1e9 + 2));
            });
        } finally {
            \Swoole\Runtime::setHookFlags($original);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function hookModes(): iterable
    {
        yield 'off' => [false];
        yield 'all' => [true];
    }

    public function testAdmissionIsSharedAcrossInstancesAndRecoversAfterTimeouts(): void
    {
        \Swoole\Coroutine\run(function (): void {
            $finished = 0;
            for ($i = 0; $i < 8; ++$i) {
                \Swoole\Coroutine::create(function () use (&$finished): void {
                    try {
                        $this->resolver()->resolve('slow.baander.app', hrtime(true) / 1e9 + 0.3);
                    } catch (\RuntimeException $failure) {
                        self::assertSame('DNS lookup deadline exceeded.', $failure->getMessage());
                    } finally {
                        ++$finished;
                    }
                });
            }
            \Swoole\Coroutine::sleep(0.03);
            try {
                $this->resolver()->resolve('complete.baander.app', hrtime(true) / 1e9 + 1);
                self::fail('The ninth concurrent lookup must be refused.');
            } catch (\RuntimeException $failure) {
                self::assertSame('DNS lookup capacity exhausted.', $failure->getMessage());
            }
            $cleanupDeadline = hrtime(true) / 1e9 + 2;
            while ($finished < 8 && hrtime(true) / 1e9 < $cleanupDeadline) {
                \Swoole\Coroutine::sleep(0.01);
            }
            self::assertSame(8, $finished);
            self::assertCount(3, $this->resolver()->resolve('complete.baander.app', hrtime(true) / 1e9 + 2));
        });
    }

    public function testCanceledCallerHandsOffCleanupWithoutLeavingChildren(): void
    {
        \Swoole\Coroutine\run(function (): void {
            $cancelled = false;
            $cid = \Swoole\Coroutine::create(function () use (&$cancelled): void {
                try {
                    $this->resolver()->resolve('slow.baander.app', hrtime(true) / 1e9 + 3);
                } catch (\App\Shared\Infrastructure\Swoole\CoroutineCanceledException) {
                    $cancelled = true;
                }
            });
            \Swoole\Coroutine::sleep(0.05);
            self::assertTrue(\Swoole\Coroutine::cancel($cid));
            \Swoole\Coroutine::sleep(0.1);
            self::assertTrue($cancelled);
            self::assertSame('', trim(file_get_contents('/proc/self/task/' . getmypid() . '/children')));
            self::assertCount(3, $this->resolver()->resolve('complete.baander.app', hrtime(true) / 1e9 + 2));
        });
    }

    public function testExpiredDeadlineDoesNotLaunch(): void
    {
        $this->expectExceptionMessage('DNS lookup deadline exceeded.');
        $this->resolver()->resolve('slow.baander.app', hrtime(true) / 1e9 - 1);
    }

    public function testChildDoesNotInheritSecrets(): void
    {
        putenv('BAANDER_DNS_SECRET=baander.app-private');
        try {
            self::assertCount(3, $this->resolver()->resolve('environment.baander.app', hrtime(true) / 1e9 + 2));
        } finally {
            putenv('BAANDER_DNS_SECRET');
        }
    }
}
