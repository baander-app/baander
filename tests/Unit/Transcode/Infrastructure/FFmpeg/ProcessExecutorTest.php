<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\FFmpeg;

use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\NativeExecutionException;
use App\Shared\Infrastructure\Worker\NativeExecutionHandle;
use PHPUnit\Framework\TestCase;

final class ProcessExecutorTest extends TestCase
{
    protected function tearDown(): void
    {
        ProcessExecutor::configureNativeExecution(null);
    }

    public function testRemoteExecutionPreservesLiteralArgumentsAndOutput(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $handle = new NativeExecutionHandle(str_repeat('a', 64), 1);
        $argv = ['ffprobe', '/media/a $(touch marker); file'];
        $client->expects(self::once())->method('submit')->with($argv, 60)->willReturn($handle);
        $client->expects(self::once())->method('inspect')->with($handle)->willReturn(['state' => 'exited', 'exitCode' => 7, 'stdout' => 'output', 'stderr' => 'diagnostic']);
        $client->expects(self::once())->method('release')->with($handle)->willReturn(true);
        $client->expects(self::never())->method('signal');
        ProcessExecutor::configureNativeExecution($client);
        self::assertSame(['code' => 7, 'output' => 'output', 'error' => 'diagnostic'], ProcessExecutor::exec($argv, 60));
    }

    public function testStopConfirmsRemoteTerminationBeforeReleasing(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $handle = new NativeExecutionHandle(str_repeat('a', 64), 1);
        $client->method('submit')->willReturn($handle);
        $client->expects(self::once())->method('signal')->with($handle, 'stop')->willReturn(['state' => 'running']);
        $client->expects(self::once())->method('inspect')->willReturn(['state' => 'exited']);
        $client->expects(self::once())->method('release')->with($handle)->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);
        self::assertSame(-1, ProcessExecutor::exec(['ffmpeg'], 60, static fn (): bool => false)['code']);
    }

    public function testUnknownRemoteStatePropagatesUncertainty(): void
    {
        $client = $this->createStub(NativeExecutionClientInterface::class);
        $handle = new NativeExecutionHandle(str_repeat('a', 64), 1);
        $client->method('submit')->willReturn($handle);
        $client->method('inspect')->willReturnOnConsecutiveCalls(['state' => 'unknown'], ['state' => 'exited']);
        $client->method('signal')->willReturn(['state' => 'running']);
        $client->method('release')->willReturn(true);
        ProcessExecutor::configureNativeExecution($client);
        $this->expectException(NativeExecutionException::class);
        ProcessExecutor::exec(['ffmpeg']);
    }

    public function testRemoteModeRejectsShellCommandsBeforeSubmission(): void
    {
        $client = $this->createMock(NativeExecutionClientInterface::class);
        $client->expects(self::never())->method('submit');
        ProcessExecutor::configureNativeExecution($client);
        $this->expectException(\RuntimeException::class);
        ProcessExecutor::exec('ffmpeg -version');
    }

    public function testNativeProcessReportsSuccessfulOutput(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fwrite(STDOUT, "encoded");');

        $result = ProcessExecutor::exec($command);

        self::assertSame(['code' => 0, 'output' => 'encoded', 'error' => ''], $result);
    }

    public function testNativeProcessRetainsFailureExitCodeAndBothOutputStreams(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('fwrite(STDOUT, "output"); fwrite(STDERR, "diagnostic"); exit(7);');

        $result = ProcessExecutor::exec($command);

        self::assertSame(['code' => 7, 'output' => 'output', 'error' => 'diagnostic'], $result);
    }
}
