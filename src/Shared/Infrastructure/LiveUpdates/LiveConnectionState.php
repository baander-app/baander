<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LiveUpdates;

use App\Shared\Application\LiveUpdates\LiveMessage;
use App\Shared\Application\LiveUpdates\LiveSnapshot;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class LiveConnectionState
{
    private readonly string $subscriptionId;
    private ?LiveSnapshot $baseline = null;
    private int $generation = 1;
    private bool $degraded = false;
    private float $lastHealth = 0;
    private float $lastRead = 0;

    public function __construct(public readonly Uuid $principal)
    {
        $this->subscriptionId = random_bytes(16) |> bin2hex(...);
    }

    /** @return list<LiveMessage> */
    public function observe(LiveSnapshot $snapshot, bool $publicationFailed = false): array
    {
        $this->lastRead = microtime(true);
        $previous = $this->baseline;
        $wasDegraded = $this->degraded;
        $this->degraded = $publicationFailed || $snapshot->health->isDegraded();
        $this->baseline = $snapshot;
        if ($previous === null) {
            return [$this->frame('live.ready', array_column($snapshot->families, 'value'))];
        }
        if ($previous->authorizationFingerprint !== $snapshot->authorizationFingerprint) {
            ++$this->generation;
            return [$this->frame('live.reset', array_values(array_unique([...array_column($previous->families, 'value'), ...array_column($snapshot->families, 'value')])))];
        }
        $changed = [];
        foreach ($snapshot->revisions as $scope => $revision) {
            if (($previous->revisions[$scope] ?? '0') !== $revision) {
                $changed[explode(':', $scope, 2)[0]] = true;
            }
        }
        $families = array_values(array_filter(array_column($snapshot->families, 'value'), static fn(string $family): bool => isset($changed[$family])));
        if ($families !== []) {
            return [$this->frame('live.changed', $families)];
        }
        if ($wasDegraded !== $this->degraded || microtime(true) - $this->lastHealth >= 5) {
            return [$this->frame('live.health', [])];
        }
        return [];
    }

    public function successfulReadAgeMs(): ?int
    {
        return $this->lastRead === 0.0 ? null : max(0, (int) ((microtime(true) - $this->lastRead) * 1000));
    }

    public function heartbeat(): ?LiveMessage
    {
        if ($this->baseline === null) {
            return null;
        }
        if (microtime(true) - $this->lastRead > 5) {
            return $this->failed();
        }
        return microtime(true) - $this->lastHealth >= 5 ? $this->frame('live.health', []) : null;
    }

    public function failed(): ?LiveMessage
    {
        $changed = !$this->degraded;
        $this->degraded = true;
        return $this->baseline !== null && ($changed || microtime(true) - $this->lastHealth >= 5)
            ? $this->frame('live.health', []) : null;
    }

    /** @param list<string> $families */
    private function frame(string $type, array $families): LiveMessage
    {
        $baseline = $this->baseline ?? throw new \LogicException('Live observation has no baseline.');
        $this->lastHealth = microtime(true);
        return LiveMessage::fromArray([
            'version' => 1,
            'type' => $type,
            'subscriptionId' => $this->subscriptionId,
            'authorizationGeneration' => (string) $this->generation,
            'authorizationFingerprint' => $baseline->authorizationFingerprint,
            'families' => $families,
            'health' => ['state' => $this->degraded ? 'degraded' : 'current', 'oldestPendingAgeMs' => $baseline->health->oldestPendingAgeMs],
        ]);
    }
}
