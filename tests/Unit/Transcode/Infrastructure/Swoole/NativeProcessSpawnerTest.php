<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
use App\Transcode\Infrastructure\Swoole\NativeProcessSpawner;
use PHPUnit\Framework\TestCase;

final class NativeProcessSpawnerTest extends TestCase
{
    public function testSignalsAndReleaseUseTheCapturedOpaqueAttempt(): void
    {
        $handle = new NativeExecutionHandle(str_repeat('a', 64), 1);
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::once())->method('submit')->with(['/usr/bin/ffmpeg', '-version'], 86400)->willReturn($handle);
        $client->expects(self::exactly(3))->method('signal')->with($handle, self::callback(static fn(string $op): bool => in_array($op, ['pause', 'resume', 'stop'], true)))->willReturn(['state' => 'running']);
        $client->expects(self::once())->method('inspect')->with($handle)->willReturn(['state' => 'exited', 'stdout' => '', 'stderr' => 'bounded output', 'exitCode' => 0, 'error' => null]);
        $client->expects(self::once())->method('release')->with($handle)->willReturn(true);
        $spawner = new NativeProcessSpawner($client);
        self::assertSame($handle, $spawner->spawn(['/usr/bin/ffmpeg', '-version']));
        foreach (['pause', 'resume', 'stop'] as $operation) {
            $spawner->signal($handle, $operation);
        }
        $spawner->close($handle);
    }

    public function testUnknownAttemptCannotBeReleasedAsSuccessfulCleanup(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->method('inspect')->willReturn(['state' => 'unknown']);
        $client->expects(self::never())->method('release');
        $this->expectException(NativeExecutionException::class);
        (new NativeProcessSpawner($client))->close(new NativeExecutionHandle(str_repeat('b', 64), 3));
    }

    public function testFailedTerminalReleaseIsUncertain(): void
    {
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $client->method('inspect')->willReturn(['state' => 'exited', 'stdout' => '', 'stderr' => '', 'exitCode' => 0, 'error' => null]);
        $client->method('release')->willReturn(false);
        $this->expectException(NativeExecutionException::class);
        (new NativeProcessSpawner($client))->close(new NativeExecutionHandle(str_repeat('c', 64), 2));
    }
}
