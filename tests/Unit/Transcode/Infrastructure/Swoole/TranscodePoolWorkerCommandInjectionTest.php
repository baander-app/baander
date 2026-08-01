<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Swoole;

use App\Transcode\Infrastructure\Swoole\TranscodePoolWorker;
use PHPUnit\Framework\TestCase;

/**
 * Confirms that TranscodePoolWorker interpolates raw payload fields into the
 * FFmpeg shell command without escaping, allowing arbitrary shell command
 * injection via values such as decoder_flags.
 */
final class TranscodePoolWorkerCommandInjectionTest extends TestCase
{
    /**
     * decoder_flags is concatenated directly into the command string. A
     * malicious value containing a command separator can terminate the FFmpeg
     * command and execute an injected command. The benign `echo INJECTED`
     * payload below proves the field is treated as raw shell text.
     *
     * The expected (secure) behavior is that the worker either rejects the
     * payload or escapes the flags, causing the encode to fail instead of
     * reporting success.
     */
    public function testDecoderFlagsAllowArbitraryShellCommandExecution(): void
    {
        $worker = new TranscodePoolWorker();

        $payload = json_encode([
            'type' => 'encode_segment',
            'source_path' => '/nonexistent/source.mkv',
            'output_path' => '/tmp/out/seg_0.mp4',
            'start_time' => 0.0,
            'duration' => 6.0,
            'encoder_config' => 'libx265',
            'video_bitrate' => 1_000_000,
            'max_bitrate' => 1_500_000,
            'buffer_size' => 2_000_000,
            'decoder_flags' => '; echo INJECTED ',
        ], JSON_THROW_ON_ERROR);

        $result = json_decode($worker->handle($payload), true, 512, JSON_THROW_ON_ERROR);

        // A properly sanitized implementation should fail to encode a
        // non-existent source and must not report success. Because the raw
        // decoder_flags are injected, the shell executes the injected echo
        // command and returns success.
        $this->assertArrayNotHasKey('success', $result);
    }
}
