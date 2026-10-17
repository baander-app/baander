<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Transcode\Infrastructure\Swoole\TranscodePoolWorker;
use PHPUnit\Framework\TestCase;

final class TranscodePoolWorkerProcessTest extends TestCase
{
    public function testWorkerCapturesSuccessfulProcessOutput(): void
    {
        $command = [PHP_BINARY, '-r', 'fwrite(STDOUT, "encoded");'];
        $executor = new \ReflectionMethod(TranscodePoolWorker::class, 'exec');

        $result = $executor->invoke(new TranscodePoolWorker(), $command, 5);

        self::assertSame(['code' => 0, 'output' => 'encoded', 'stderr' => ''], $result);
    }

    public function testWorkerRetainsFailureExitCodeAndDiagnosticStreams(): void
    {
        $command = [PHP_BINARY, '-r', 'fwrite(STDOUT, "output"); fwrite(STDERR, "diagnostic"); exit(7);'];
        $executor = new \ReflectionMethod(TranscodePoolWorker::class, 'exec');

        $result = $executor->invoke(new TranscodePoolWorker(), $command, 5);

        self::assertSame(['code' => 7, 'output' => 'output', 'stderr' => 'diagnostic'], $result);
    }
    public function testHelperLaunchesExecutableAsDirectChild(): void
    {
        $executor = new \ReflectionMethod(TranscodePoolWorker::class, 'exec');
        $result = $executor->invoke(new TranscodePoolWorker(), [PHP_BINARY, '-r', 'preg_match("/^PPid:\\s+(\\d+)$/m", file_get_contents("/proc/self/status"), $match); echo $match[1];'], 5);
        self::assertSame(0, $result['code']);
        self::assertSame((string) getmypid(), $result['output']);
    }

    public function testOwnedProbeReturnsRealSourceMetadata(): void
    {
        $source = sys_get_temp_dir() . '/baander probe ' . bin2hex(random_bytes(8)) . '.mp4';
        try {
            $process = new \Symfony\Component\Process\Process(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
                '-f', 'lavfi', '-i', 'color=size=64x64:rate=1', '-t', '1', '-c:v', 'libx264', '-threads', '1', $source]);
            $process->mustRun();
            $metadata = json_decode((new TranscodePoolWorker())->handle(json_encode([
                'type' => 'probe_video', 'source_path' => $source,
            ], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('h264', $metadata['streams'][0]['codec_name']);
            self::assertSame('video', $metadata['streams'][0]['codec_type']);
            self::assertSame(64, $metadata['streams'][0]['width']);
        } finally {
            @unlink($source);
        }
    }
}
