<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class AutoscalingPolicy
{
    private int $target;
    private int $recoverySamples = 0;
    private ?float $lastEvidenceAt = null;
    private ?float $lastTickAt = null;
    /** @var list<float> */
    private array $lastSampleTimes = [];
    private ?float $upSince = null;
    private ?float $downSince = null;
    private ?float $pressureSince = null;
    private ?float $lastChangeAt = null;
    private string $lastChangeReason = 'startup';

    public function __construct(private readonly WorkerAutoscalingProfile $profile)
    {
        $this->target = $profile->minimumConcurrency;
    }

    public function decide(QueueObservation $queue, ResourceObservation $resources, PoolObservation $pool,
        float $now, bool $authorityValid = true): ScalingDecision
    {
        $clockValid = is_finite($now) && $now >= 0 && ($this->lastTickAt === null || $now >= $this->lastTickAt);
        if ($clockValid) {
            if ($this->lastTickAt !== null && $now - $this->lastTickAt > $this->profile->maxSampleAgeMs / 1000) {
                $this->recoverySamples = 0;
                $this->upSince = $this->downSince = $this->pressureSince = null;
            }
            $this->lastTickAt = $now;
        }
        $evidence = $this->evidence($queue, $resources, $pool, $now);
        if (!$authorityValid) {
            return $this->degrade(0, 'authority_unavailable', $evidence, $now);
        }
        if ($clockValid && $this->fresh($resources->sampledAt, $now)
            && (($this->measurement($resources->memoryRatio) && $resources->memoryRatio >= $this->profile->memoryStopPercent / 100)
                || ($this->measurement($resources->pidsRatio) && $resources->pidsRatio >= $this->profile->pidsStopPercent / 100))) {
            return $this->degrade(0, 'severe_resource_pressure', $evidence, $now);
        }
        if (!$clockValid || !$this->validEvidence($queue, $resources, $pool, $now)) {
            return $this->degrade(min(1, $this->target), 'measurements_unavailable', $evidence, $now);
        }
        $this->lastSampleTimes = [$queue->sampledAt, $resources->sampledAt, $pool->sampledAt];
        $sampleAt = min(...$this->lastSampleTimes);
        $newSample = $this->lastEvidenceAt === null || $sampleAt > $this->lastEvidenceAt;
        if ($newSample) {
            $this->lastEvidenceAt = $sampleAt;
            $this->recoverySamples = min(2, $this->recoverySamples + 1);
        }
        if ($this->recoverySamples < 2) {
            return $this->decision('recovering_measurements', 'conservative', $evidence, $now);
        }
        if ($this->target < $this->profile->minimumConcurrency) {
            if ($newSample && ($this->target === 0 || $this->cooldown($now) === 0.0)) {
                $this->change($this->target + 1, 'recovered_minimum', $now);
            }
            return $this->decision('recovering_minimum', 'known', $evidence, $now);
        }
        $waiting = min(1000000000, (float) $queue->unreadCount + (float) $queue->dueCount);
        $oldestAge = max($queue->unreadCount > 0 ? ($queue->oldestUnreadAgeSeconds ?? 0) : 0,
            $queue->dueCount > 0 ? ($queue->oldestDueAgeSeconds ?? 0) : 0);
        $duration = max($pool->completedOccupancySeconds ?? 0, $pool->maxInFlightSeconds ?? 0);
        $cold = $duration <= 0;
        $duration = $cold ? $this->profile->coldDurationMs / 1000 : $duration;
        $demandSeconds = min(3600000000.0, $duration * $waiting / max(1, $this->target));
        $evidence['estimatedDemandSeconds'] = $demandSeconds;
        $evidence['durationSeconds'] = $duration;
        $pressureLow = $resources->cpuUtilization < $this->profile->cpuCeilingPercent / 100
            && $resources->cpuThrottledRatio < 0.1
            && $resources->memoryRatio < $this->profile->memoryCeilingPercent / 100
            && $resources->pidsRatio < $this->profile->pidsCeilingPercent / 100;
        $utilized = $pool->busy / max(1, $this->target) >= 0.75;
        $up = $waiting > 0 && $oldestAge >= $this->profile->targetBacklogAgeMs / 1000
            && ($cold || $demandSeconds >= $this->profile->targetBacklogAgeMs / 1000)
            && ($utilized || ($cold && $pool->occupied >= $this->target)) && $pressureLow;
        $down = $waiting === 0.0 && $pool->busy < $this->target
            && $resources->cpuUtilization < $this->profile->cpuCeilingPercent / 200;
        $this->upSince = $up ? ($this->upSince ?? $now) : null;
        $this->downSince = $down ? ($this->downSince ?? $now) : null;
        $this->pressureSince = !$pressureLow ? ($this->pressureSince ?? $now) : null;
        if ($newSample && $this->cooldown($now) === 0.0) {
            if ($this->pressureSince !== null && $now - $this->pressureSince >= $this->profile->scaleUpSustainMs / 1000
                && $this->target > $this->profile->minimumConcurrency) {
                $this->change($this->target - 1, 'resource_pressure', $now);
                return $this->decision($this->lastChangeReason, 'known', $evidence, $now);
            }
            if ($this->upSince !== null && $now - $this->upSince >= $this->profile->scaleUpSustainMs / 1000
                && $this->target < $this->profile->maximumConcurrency) {
                $this->change($this->target + 1, $cold ? 'cold_exploration' : 'aged_service_demand', $now);
                return $this->decision($this->lastChangeReason, 'known', $evidence, $now);
            }
            if ($this->downSince !== null && $now - $this->downSince >= $this->profile->scaleDownSustainMs / 1000
                && $this->target > $this->profile->minimumConcurrency) {
                $this->change($this->target - 1, 'low_demand', $now);
                return $this->decision($this->lastChangeReason, 'known', $evidence, $now);
            }
        }
        return $this->decision(!$pressureLow ? 'resource_growth_blocked' : ($up ? 'sustaining_demand' : 'stable'), 'known', $evidence, $now);
    }

    private function validEvidence(QueueObservation $queue, ResourceObservation $resources, PoolObservation $pool, float $now): bool
    {
        if (!$this->fresh($queue->sampledAt, $now) || !$this->fresh($resources->sampledAt, $now)
            || !$this->fresh($pool->sampledAt, $now) || !$queue->isKnown() || !$resources->isKnown()) {
            return false;
        }
        foreach ([$queue->sampledAt, $resources->sampledAt, $pool->sampledAt] as $index => $sampleTime) {
            if (isset($this->lastSampleTimes[$index]) && $sampleTime < $this->lastSampleTimes[$index]) {
                return false;
            }
        }
        foreach ([$queue->unreadCount, $queue->pendingCount, $queue->dueCount, $queue->futureCount] as $count) {
            if ($count === null || $count < 0) {
                return false;
            }
        }
        foreach ([$resources->cpuUtilization, $resources->cpuThrottledRatio, $resources->memoryRatio, $resources->pidsRatio] as $ratio) {
            if (!$this->measurement($ratio)) {
                return false;
            }
        }
        foreach ([$queue->oldestUnreadAgeSeconds, $queue->oldestDueAgeSeconds, $pool->completedOccupancySeconds, $pool->maxInFlightSeconds] as $value) {
            if ($value !== null && !$this->measurement($value)) {
                return false;
            }
        }
        if (($queue->unreadCount > 0 && $queue->oldestUnreadAgeSeconds === null)
            || ($queue->dueCount > 0 && $queue->oldestDueAgeSeconds === null)) {
            return false;
        }
        return $pool->knownSlotCount === $this->profile->maximumConcurrency
            && $pool->occupied >= 0 && $pool->occupied <= $pool->knownSlotCount
            && $pool->busy >= 0 && $pool->busy <= $pool->occupied
            && $pool->parked >= 0 && $pool->parked + $pool->occupied <= $pool->knownSlotCount;
    }

    private function fresh(float $sampledAt, float $now): bool
    {
        return is_finite($sampledAt) && $sampledAt >= 0 && $sampledAt <= $now + 0.25
            && $now - $sampledAt <= $this->profile->maxSampleAgeMs / 1000;
    }

    private function measurement(?float $value): bool
    {
        return $value !== null && is_finite($value) && $value >= 0 && $value <= 3600000000;
    }

    /** @param array<string, int|float|string|null> $evidence */
    private function degrade(int $target, string $reason, array $evidence, float $now): ScalingDecision
    {
        $this->recoverySamples = 0;
        $this->upSince = $this->downSince = $this->pressureSince = null;
        if ($this->target !== $target) {
            $this->change($target, $reason, is_finite($now) && $now >= 0 ? $now : ($this->lastTickAt ?? 0));
        }
        return $this->decision($reason, 'conservative', $evidence, $now);
    }

    private function change(int $target, string $reason, float $now): void
    {
        $this->target = $target;
        $this->lastChangeAt = $now;
        $this->lastChangeReason = $reason;
        $this->upSince = $this->downSince = $this->pressureSince = null;
    }

    private function cooldown(float $now): float
    {
        if ($this->lastChangeAt === null || !is_finite($now)) {
            return 0;
        }
        return max(0.0, $this->profile->changeCooldownMs / 1000 - max(0.0, $now - $this->lastChangeAt));
    }

    /** @param array<string, int|float|string|null> $evidence */
    private function decision(string $reason, string $confidence, array $evidence, float $now): ScalingDecision
    {
        $evidence['lastChangeReason'] = $this->lastChangeReason;
        return new ScalingDecision($this->target, $reason, $confidence, $this->cooldown($now), $evidence);
    }

    /** @return array<string, int|float|string|null> */
    private function evidence(QueueObservation $queue, ResourceObservation $resources, PoolObservation $pool, float $now): array
    {
        $age = static fn (float $at): ?float => is_finite($at) && is_finite($now) && $at >= 0 && $at <= $now + 0.25 ? max(0.0, $now - $at) : null;
        $finite = fn (?float $value): ?float => $this->measurement($value) ? $value : null;
        $count = static fn (?int $value): ?int => $value !== null && $value >= 0 ? $value : null;
        $state = static fn (string $value): string => in_array($value, ['known', 'unknown', 'unavailable'], true) ? $value : 'unknown';
        $completedDuration = $finite($pool->completedOccupancySeconds);
        $inFlightDuration = $finite($pool->maxInFlightSeconds);
        return [
            'queueState' => in_array($queue->state, ['known', 'unknown_lag', 'uninitialized', 'unavailable'], true) ? $queue->state : 'unknown',
            'resourceState' => $state($resources->state),
            'unreadCount' => $count($queue->unreadCount),
            'pendingCount' => $count($queue->pendingCount),
            'dueCount' => $count($queue->dueCount),
            'futureCount' => $count($queue->futureCount),
            'oldestUnreadAgeSeconds' => $finite($queue->oldestUnreadAgeSeconds),
            'oldestDueAgeSeconds' => $finite($queue->oldestDueAgeSeconds),
            'durationSeconds' => $completedDuration !== null || $inFlightDuration !== null
                ? max($completedDuration ?? 0, $inFlightDuration ?? 0) : null,
            'queueSampleAgeSeconds' => $age($queue->sampledAt),
            'resourceSampleAgeSeconds' => $age($resources->sampledAt),
            'poolSampleAgeSeconds' => $age($pool->sampledAt),
            'occupied' => max(0, min(4, $pool->occupied)),
            'busy' => max(0, min(4, $pool->busy)),
            'parked' => max(0, min(4, $pool->parked)),
            'knownSlotCount' => max(0, min(4, $pool->knownSlotCount)),
            'cpuUtilization' => $finite($resources->cpuUtilization),
            'cpuThrottledRatio' => $finite($resources->cpuThrottledRatio),
            'memoryRatio' => $finite($resources->memoryRatio),
            'pidsRatio' => $finite($resources->pidsRatio),
        ];
    }
}
