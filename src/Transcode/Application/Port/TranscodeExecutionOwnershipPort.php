<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Application\DTO\TranscodeExecutionClaim;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Domain\ValueObject\AudioProfile;

interface TranscodeExecutionOwnershipPort
{
    /** Serialize first admission before creating the job, inside the producer transaction. */
    public function lockAdmission(Uuid $videoId): void;
    /** Lock the current runtime before reading a session for a control mutation. */
    public function lockSession(Uuid $sessionId): void;
    public function admit(Uuid $jobId, Uuid $sessionId, string $requestId, DesiredTranscodeState $desired, int $deadlineSeconds = 5): TranscodeRuntimeSnapshot;
    public function control(Uuid $jobId, Uuid $sessionId, string $requestId, ?string $status = null, ?AudioProfile $audioProfile = null, ?float $position = null, ?int $segmentIndex = null, bool $seek = true): TranscodeRuntimeSnapshot;
    public function find(Uuid $jobId): ?TranscodeRuntimeSnapshot;
    /** @return list<TranscodeRuntimeSnapshot> */
    public function pending(int $limit = 100): array;
    public function claim(Uuid $jobId, MediaWorkerIdentity $worker, int $leaseSeconds = 15): ?TranscodeExecutionClaim;
    public function refresh(TranscodeExecutionClaim $claim): ?TranscodeExecutionClaim;
    public function renew(TranscodeExecutionClaim $claim, int $leaseSeconds = 15): bool;
    /** Acceptance, readiness, progress and terminal effects require the full current fence. */
    public function observe(TranscodeExecutionClaim $claim, string $outcome, ?string $outputDirectory = null, ?string $error = null): bool;
    /** Run aggregate saves on the same connection while holding the full live ownership fence. No filesystem or external work. @param callable(): void $operation */
    public function withOwnership(TranscodeExecutionClaim $claim, callable $operation): bool;
    public function release(TranscodeExecutionClaim $claim): bool;
    public function expireAdmission(Uuid $jobId, Uuid $sessionId, string $requestId): bool;
}
