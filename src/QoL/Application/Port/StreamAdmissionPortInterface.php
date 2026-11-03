<?php

declare(strict_types=1);

namespace App\QoL\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Stream admission, observation and completion published by QoL for transcode sessions.
 *
 * Admission is the synchronous budget veto: callers must invoke it before the
 * encoding loop starts and must not start the loop when it throws. While the stream
 * runs, callers report the encoder's own work so far; completion feeds that work to
 * the learning model and releases the job's stream allocation.
 *
 * Encoder work is the encoder process's cumulative CPU seconds and the seconds of
 * output it has produced. Their ratio does not shrink when other encodes contend for
 * the host, so it measures this stream alone.
 */
interface StreamAdmissionPortInterface
{
    /**
     * Admit a stream into the CPU budget and track it as active.
     *
     * @throws \RuntimeException QoL's stream-budget exhaustion exception when no tier fits the remaining budget
     */
    public function admit(
        Uuid   $jobId,
        string $qualityTier,
        int    $targetBitrate,
        int    $sourceHeight,
        bool   $hardwareAccelerated,
    ): void;

    /**
     * Record the running stream's encoder work so far for the mid-stream budget check.
     */
    public function observe(Uuid $jobId, float $encoderCpuSeconds, float $outputSeconds): void;

    /**
     * Record the finished stream's encoder work as a learning sample and release its allocation.
     */
    public function complete(
        Uuid   $jobId,
        string $qualityTier,
        int    $targetBitrate,
        int    $sourceHeight,
        string $sourceCodec,
        bool   $hardwareAccelerated,
        float  $encoderCpuSeconds,
        float  $outputSeconds,
    ): void;
}
