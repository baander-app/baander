<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\NativeDnsResolver;
use App\Shared\Infrastructure\Swoole\CoroutineCanceledException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeDnsResolverRuntimeTest extends TestCase
{
    #[DataProvider('hookModes')]
    public function testRepeatedTimeoutAndCancellationReleaseChildrenDescriptorsAndCapacity(int $hooks): void
    {
        $original = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::setHookFlags($hooks);
        try {
            \Swoole\Coroutine\run(function (): void {
                $resolver = new NativeDnsResolver(dirname(__DIR__, 4) . '/Fixtures/Http/dns-helper.php');
                self::assertCount(3, $resolver->resolve('complete.baander.app', hrtime(true) / 1e9 + 2));
                // Initialize the hooked /proc observer's own notification pipe before counting.
                self::assertSame('', $this->children());
                $baselineDescriptors = $this->descriptors();
                $descriptors = count($baselineDescriptors);
                for ($round = 0; $round < 3; ++$round) {
                    $finished = 0;
                    $cancelled = 0;
                    $cancelledCallers = [];
                    $timedOut = 0;
                    $ids = [];
                    for ($i = 0; $i < 8; ++$i) {
                        $budget = $i < 4 ? 3.0 : 1.0;
                        $ids[] = \Swoole\Coroutine::create(static function () use ($resolver, $budget, $i, &$finished, &$cancelled, &$cancelledCallers, &$timedOut): void {
                            try {
                                $resolver->resolve('slow.baander.app', hrtime(true) / 1e9 + $budget);
                                self::fail('Stalled child unexpectedly succeeded.');
                            } catch (CoroutineCanceledException) {
                                ++$cancelled;
                                $cancelledCallers[$i] = true;
                            } catch (\RuntimeException $failure) {
                                self::assertSame('DNS lookup deadline exceeded.', $failure->getMessage());
                                ++$timedOut;
                            } finally {
                                ++$finished;
                            }
                        });
                    }
                    \Swoole\Coroutine::sleep(0.03);
                    for ($i = 0; $i < 4; ++$i) {
                        // io_uring cancellation can race the short polling sleep's completion.
                        $cancelUntil = hrtime(true) / 1e9 + 0.5;
                        do {
                            $accepted = \Swoole\Coroutine::cancel($ids[$i]);
                            $error = swoole_last_error();
                            if (!$accepted && !isset($cancelledCallers[$i])) {
                                \Swoole\Coroutine::sleep(0.001);
                            }
                        } while (!$accepted && !isset($cancelledCallers[$i]) && hrtime(true) / 1e9 < $cancelUntil);
                        self::assertTrue($accepted || isset($cancelledCallers[$i]), sprintf(
                            'Caller %d (cid %d) cancellation refused: finished=%d, timedOut=%d, cancelled=%d, error=%d, exists=%s, trace=%s.',
                            $i, $ids[$i], $finished, $timedOut, $cancelled, $error,
                            json_encode(\Swoole\Coroutine::exists($ids[$i])),
                            $accepted ? '' : json_encode(\Swoole\Coroutine::getBackTrace($ids[$i])),
                        ));
                    }
                    $until = hrtime(true) / 1e9 + 2;
                    do {
                        \Swoole\Coroutine::sleep(0.01);
                    } while (($finished < 8 || $this->children() !== '') && hrtime(true) / 1e9 < $until);
                    self::assertSame(8, $finished);
                    self::assertSame(4, $cancelled);
                    self::assertSame(4, $timedOut);
                    self::assertSame('', $this->children(), 'Cleanup must reap even canceled callers\' children.');
                    self::assertSame($descriptors, count(glob('/proc/self/fd/*')),
                        'Output descriptors must not accumulate: ' . json_encode([$baselineDescriptors, $this->descriptors()]));
                    self::assertCount(3, (new NativeDnsResolver(dirname(__DIR__, 4) . '/Fixtures/Http/dns-helper.php'))
                        ->resolve('complete.baander.app', hrtime(true) / 1e9 + 2));
                }
            });
        } finally {
            \Swoole\Runtime::setHookFlags($original);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function hookModes(): iterable
    {
        yield 'native descriptors' => [0];
        yield 'all coroutine hooks' => [SWOOLE_HOOK_ALL];
    }

    public function testFreshHelperCannotRetainAnOpenParentFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-dns-parent-');
        self::assertNotFalse($path);
        $handle = fopen($path, 'w+');
        self::assertIsResource($handle);
        try {
            $resolver = new NativeDnsResolver(dirname(__DIR__, 4) . '/Fixtures/Http/dns-descriptors.php');
            self::assertSame(['8.8.8.8'], $resolver->resolve('complete.baander.app', hrtime(true) / 1e9 + 2));
            self::assertSame(6, fwrite($handle, 'parent'));
        } finally {
            fclose($handle);
            unlink($path);
        }
    }

    public function testForkedProcessDiscardsInheritedOwnershipWithoutSignalingTheParentChild(): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 4) . '/Fixtures/Http/dns-fork.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame(['inherited_ownership_reset' => true, 'parent_child_survived_fork' => true, 'reaped' => true],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function descriptors(): array
    {
        return array_map(static fn (string $path): string => (string) @readlink($path), glob('/proc/self/fd/*'));
    }

    private function children(): string
    {
        return trim((string) file_get_contents('/proc/self/task/' . getmypid() . '/children'));
    }
}
