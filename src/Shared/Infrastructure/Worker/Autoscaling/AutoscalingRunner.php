<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use App\Shared\Infrastructure\Worker\WorkerLaunchIdentity;

/** Dedicated control child; external observation never blocks supervisor renewal. */
final readonly class AutoscalingRunner
{
    public function __construct(private RedisQueueSampler $queue)
    {
    }

    public function runUntilSignalled(): void
    {
        if (getenv('BAANDER_WORKER_UNIT') !== 'control' || getenv('BAANDER_WORKER_ID') !== 'autoscaler'
            || getenv('BAANDER_WORKER_GENERATION') !== '1') {
            throw new \RuntimeException('Autoscaler requires private control admission.');
        }
        $epoch = getenv('BAANDER_WORKER_LEASE_EPOCH');
        if (!is_string($epoch) || preg_match('/^[1-9][0-9]{0,18}$/D', $epoch) !== 1
            || strlen($epoch) > strlen((string) PHP_INT_MAX)
            || (strlen($epoch) === strlen((string) PHP_INT_MAX) && strcmp($epoch, (string) PHP_INT_MAX) > 0)) {
            throw new \RuntimeException('Autoscaler requires a bounded lease epoch.');
        }
        $namespace = getenv('BAANDER_WORKER_NAMESPACE');
        $bootId = getenv('BAANDER_WORKER_BOOT_ID');
        $profileJson = getenv('BAANDER_WORKER_AUTOSCALING_PROFILE');
        $directory = getenv('BAANDER_WORKER_IPC_DIRECTORY');
        if (!is_string($namespace) || !is_string($bootId) || !is_string($profileJson) || !is_string($directory)) {
            throw new \RuntimeException('Autoscaler requires inherited deployment configuration.');
        }
        new WorkerLaunchIdentity($namespace, $bootId, 'autoscaler', 1);
        $lease = new DeploymentLease($namespace, $bootId, (int) $epoch);
        $profile = WorkerAutoscalingProfile::fromJson($profileJson);
        $ledger = new AdmissionLedger($directory, $lease, $profile);
        $channel = new ResourceChannel($directory, $lease);
        $policy = new AutoscalingPolicy($profile);
        $ledger->snapshot(hrtime(true) / 1e9);
        $running = true;
        $handler = static function () use (&$running): void {
            $running = false;
        };
        $keepRunning = static function () use (&$running): bool {
            return $running;
        };
        $previousAsyncSignals = pcntl_async_signals(true);
        $previousTerm = pcntl_signal_get_handler(SIGTERM);
        $previousInt = pcntl_signal_get_handler(SIGINT);
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        try {
            while ($keepRunning()) {
                $started = hrtime(true) / 1e9;
                $queue = $this->queue->sample($started);
                $this->tick($ledger, $policy, $channel, $profile, $queue, hrtime(true) / 1e9);
                $deadline = $started + $profile->sampleIntervalMs / 1000;
                while ($keepRunning() && ($remaining = $deadline - hrtime(true) / 1e9) > 0) {
                    usleep((int) min(50_000, ceil($remaining * 1e6)));
                }
            }
        } finally {
            pcntl_signal(SIGTERM, $previousTerm);
            pcntl_signal(SIGINT, $previousInt);
            pcntl_async_signals($previousAsyncSignals);
        }
    }

    public function tick(AdmissionLedger $ledger, AutoscalingPolicy $policy, ResourceChannel $channel,
        WorkerAutoscalingProfile $profile, QueueObservation $queue, float $now): ScalingDecision
    {
        $state = $ledger->snapshot($now);
        if ($state['fatal']) {
            throw new \RuntimeException('Autoscaler encountered uncertain delivery ownership.');
        }
        $authority = $ledger->authorityValid($now);
        $counts = AutoscalingStatus::slotSummary($state, $profile, $now, $authority);
        $pool = new PoolObservation($now, $counts['occupied'], $counts['busy'], $counts['parked'], $counts['known'],
            $state['occupancyDurationEwmaMs'] === null ? null : $state['occupancyDurationEwmaMs'] / 1000, $counts['maxInFlight']);
        $decision = $policy->decide($queue, $channel->read($now), $pool, $now, $authority);
        $ledger->publishDecision($decision->target, $decision->reason,
            [...$decision->evidence, 'confidence' => $decision->confidence, 'cooldownRemainingSeconds' => $decision->cooldownRemainingSeconds], $now);
        return $decision;
    }
}
