<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use App\Shared\Infrastructure\Worker\DeploymentLease;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class AutoscalingStatus
{
    private AdmissionLedger $ledger;
    private ResourceChannel $resources;

    public function __construct(string $ipcDirectory, DeploymentLease $lease, private WorkerAutoscalingProfile $profile)
    {
        $this->ledger = new AdmissionLedger($ipcDirectory, $lease, $profile);
        $this->resources = new ResourceChannel($ipcDirectory, $lease);
    }

    /** @return array<string, mixed> */
    public function snapshot(float $now): array
    {
        $state = $this->ledger->snapshot($now);
        $authority = $this->ledger->authorityValid($now);
        $counts = self::slotSummary($state, $this->profile, $now, $authority);
        $resource = $this->resources->read($now);
        $controllerAge = $state['controllerAt'] <= $now ? $now - $state['controllerAt'] : null;
        $fresh = $controllerAge !== null && $controllerAge < $this->profile->maxSampleAgeMs / 1000;
        return ['state' => $authority && $fresh && !$state['fatal'] ? 'known' : 'unavailable',
            'desired' => $state['target'], 'occupied' => $counts['occupied'], 'busy' => $counts['busy'],
            'idle' => $counts['idle'], 'parked' => $counts['parked'], 'missing' => $counts['missing'],
            'uncertain' => $counts['uncertain'], 'disconnected' => $counts['disconnected'],
            'authorityValid' => $authority, 'controllerFresh' => $fresh, 'controllerAgeSeconds' => $controllerAge,
            'reason' => $state['reason'], 'evidence' => $state['evidence'],
            'resources' => $resource->jsonSerialize(),
            'resourceFresh' => $resource->isKnown() && $now - $resource->sampledAt < $this->profile->maxSampleAgeMs / 1000];
    }

    /**
     * Held ownership survives missing heartbeats; stale free slots never become idle.
     * @param array<string, mixed> $state
     * @return array{occupied: int, busy: int, idle: int, parked: int, missing: int, uncertain: int, disconnected: int, known: int, maxInFlight: ?float}
     */
    public static function slotSummary(array $state, WorkerAutoscalingProfile $profile, float $now, bool $authority): array
    {
        $counts = ['occupied' => 0, 'busy' => 0, 'idle' => 0, 'parked' => 0, 'missing' => 0,
            'uncertain' => 0, 'disconnected' => 0, 'known' => 0, 'maxInFlight' => null];
        foreach ($state['slots'] as $slot) {
            $held = $slot['requestId'] !== null;
            $fresh = $slot['sequence'] > 0 && $slot['seenAt'] !== null && $slot['seenAt'] <= $now
                && $now - $slot['seenAt'] < $profile->maxSampleAgeMs / 1000;
            if ($held) {
                ++$counts['occupied'];
                $counts['maxInFlight'] = max($counts['maxInFlight'] ?? 0, max(0, $now - $slot['heldSince']));
                if (in_array($slot['state'], ['busy', 'completing'], true)) {
                    ++$counts['busy'];
                }
                if ($slot['state'] === 'uncertain') {
                    ++$counts['uncertain'];
                }
            }
            if ($authority && ($fresh || $held)) {
                ++$counts['known'];
            }
            if ($slot['state'] === 'missing') {
                ++$counts['missing'];
            } elseif (!$held && (!$fresh || !$authority)) {
                ++$counts['disconnected'];
            } elseif (in_array($slot['state'], ['idle', 'parked'], true)) {
                ++$counts[$slot['state']];
            }
        }
        return $counts;
    }
}
