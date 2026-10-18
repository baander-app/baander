<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Worker;

use App\Shared\Infrastructure\Worker\NativeExecutionClient;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Shared\Infrastructure\Worker\NativeExecutionProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeExecutionProtocolTest extends TestCase
{
    public function testFrameLimitIncludesTheDelimiter(): void
    {
        self::assertSame(['id' => 'baander.app'], NativeExecutionProtocol::decode("{\"id\":\"baander.app\"}\n", 21));
        $this->expectException(NativeExecutionException::class);
        NativeExecutionProtocol::encode(['id' => 'baander.app'], 20);
    }

    #[DataProvider('invalidFrames')]
    public function testRejectsIncompleteMultipleOrNonObjectFrames(string $frame): void
    {
        $this->expectException(\Throwable::class);
        NativeExecutionProtocol::decode($frame, 64);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFrames(): iterable
    {
        yield 'missing delimiter' => ['{"id":1}'];
        yield 'multiple frames' => ["{}\n{}\n"];
        yield 'array' => ["[]\n"];
        yield 'scalar' => ["1\n"];
        yield 'broken json' => ["{broken}\n"];
        yield 'oversized' => [str_repeat('a', 65) . "\n"];
    }

    public function testSilentPeerHasOneSecondAggregateDeadline(): void
    {
        $path = sys_get_temp_dir() . '/baander-native-silent-' . bin2hex(random_bytes(6)) . '.sock';
        $server = stream_socket_server('unix://' . $path, $errno, $error);
        self::assertIsResource($server);
        $client = new NativeExecutionClient($path, 'baander.app:workers', str_repeat('a', 32), 1, 'test', 1);
        $start = hrtime(true);
        try {
            $client->handshake();
            self::fail('A silent peer must not grant readiness.');
        } catch (NativeExecutionException $error) {
            self::assertTrue($error->uncertain);
            self::assertStringContainsString('deadline', $error->getMessage());
            self::assertLessThan(1.2, (hrtime(true) - $start) / 1_000_000_000);
        } finally {
            fclose($server);
            unlink($path);
        }
    }

    public function testSilentPeerYieldsToOtherCoroutines(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Requires Swoole.');
        }
        $path = sys_get_temp_dir() . '/baander-native-coroutine-' . bin2hex(random_bytes(6)) . '.sock';
        $server = stream_socket_server('unix://' . $path, $errno, $error);
        self::assertIsResource($server);
        $ticks = 0;
        try {
            \Swoole\Coroutine\run(function () use ($path, &$ticks): void {
                \Swoole\Coroutine::create(function () use (&$ticks): void {
                    for ($i = 0; $i < 10; ++$i) {
                        \Swoole\Coroutine::sleep(0.01);
                        ++$ticks;
                    }
                });
                try {
                    (new NativeExecutionClient($path, 'baander.app:workers', str_repeat('a', 32), 1, 'test', 1))->handshake();
                    self::fail('Silent peer must time out.');
                } catch (NativeExecutionException) {
                    self::assertSame(10, $ticks);
                }
            });
        } finally {
            fclose($server);
            unlink($path);
        }
    }
}
