<?php

declare(strict_types=1);

namespace App\QoL\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Stream admission and completion published by QoL for transcode sessions.
 *
 * Admission is the synchronous budget veto: callers must invoke it before the
 * encoding loop starts and must not start the loop when it throws. Completion
 * feeds the learning model and releases the job's stream allocation.
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
     * Record the finished stream as a learning sample and release its allocation.
     */
    public function complete(
        Uuid   $jobId,
        string $qualityTier,
        int    $targetBitrate,
        int    $sourceHeight,
        string $sourceCodec,
        bool   $hardwareAccelerated,
    ): void;
}
