<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Conservatively translates committed lease replies into local monotonic authority. */
#[Exclude]
final class LeaseAuthority
{
    private ?DeploymentLease $grant = null;
    private ?float $deadline = null;
    private ?float $lastTime = null;
    private ?int $pendingSequence = null;
    private ?float $pendingDeadline = null;
    private int $sequence = 0;
    private bool $revoked = false;

    public function __construct(
        public readonly string $namespace,
        public readonly string $bootId,
        private readonly int $ttlSeconds,
        private readonly float $renewAheadSeconds,
        private readonly float $safetyMarginSeconds = 0.1,
    ) {
        DeploymentLease::validateIdentity($namespace, $bootId);
        if ($ttlSeconds < 1 || $ttlSeconds > 3600 || !is_finite($renewAheadSeconds)
            || !is_finite($safetyMarginSeconds) || $safetyMarginSeconds <= 0 || $safetyMarginSeconds >= $ttlSeconds
            || $renewAheadSeconds <= 0 || $renewAheadSeconds >= $ttlSeconds - $safetyMarginSeconds
        ) {
            throw new InvalidArgumentException('Lease authority requires a TTL of 1..3600 seconds and positive safety and renewal margins fitting within its TTL.');
        }
    }

    /**
     * Reserve one asynchronous request. No database or process work runs here.
     *
     * @return array{action: 'acquire'|'renew', sequence: int, namespace: string, bootId: string, epoch: ?int, ttlSeconds: int}|null
     */
    public function request(float $now): ?array
    {
        $this->advance($now);
        if ($this->revoked || $this->pendingSequence !== null
            || ($this->deadline !== null && $now < $this->deadline - $this->renewAheadSeconds)
        ) {
            return null;
        }
        $deadline = $now + ($this->ttlSeconds - $this->safetyMarginSeconds);
        if (!is_finite($deadline) || $deadline <= $now) {
            $this->revoked = true;
            throw new InvalidArgumentException('Lease request deadline must be finite and later than its start.');
        }
        $this->pendingSequence = ++$this->sequence;
        $this->pendingDeadline = $deadline;

        return [
            'action' => $this->grant === null ? 'acquire' : 'renew',
            'sequence' => $this->pendingSequence,
            'namespace' => $this->namespace,
            'bootId' => $this->bootId,
            'epoch' => $this->grant?->epoch,
            'ttlSeconds' => $this->ttlSeconds,
        ];
    }

    /** A null reply includes transport failure; only the current request can supply a grant. */
    public function accept(int $sequence, ?DeploymentLease $grant, float $now): bool
    {
        $this->advance($now);
        if ($this->revoked || $this->pendingSequence !== $sequence || $this->pendingDeadline === null) {
            return false;
        }
        if ($grant === null || $grant->namespace !== $this->namespace || $grant->bootId !== $this->bootId
            || ($this->grant !== null && $grant->epoch !== $this->grant->epoch)
        ) {
            $this->revoked = true;
            $this->pendingSequence = null;
            $this->pendingDeadline = null;
            return false;
        }
        $this->grant = $grant;
        $this->deadline = $this->pendingDeadline;
        $this->pendingSequence = null;
        $this->pendingDeadline = null;

        return true;
    }

    public function hasAuthority(float $now): bool
    {
        $this->advance($now);

        return !$this->revoked && $this->grant !== null;
    }

    public function isRevoked(float $now): bool
    {
        $this->advance($now);

        return $this->revoked;
    }

    /** Helper timeout, invalid framing and explicit shutdown permanently close authority. */
    public function revoke(float $now): void
    {
        $this->advance($now);
        $this->revoked = true;
    }

    /** Returns a currently authoritative token, never a token after local authority has expired. */
    public function lease(float $now): ?DeploymentLease
    {
        $this->advance($now);

        return $this->revoked ? null : $this->grant;
    }

    private function advance(float $now): void
    {
        if (!is_finite($now) || $now < 0 || ($this->lastTime !== null && $now < $this->lastTime)) {
            $this->revoked = true;
            throw new InvalidArgumentException('Lease authority requires finite, nonnegative, non-decreasing monotonic time.');
        }
        $this->lastTime = $now;
        if (($this->deadline !== null && $now >= $this->deadline)
            || ($this->pendingDeadline !== null && $now >= $this->pendingDeadline)
        ) {
            $this->revoked = true;
        }
    }
}
