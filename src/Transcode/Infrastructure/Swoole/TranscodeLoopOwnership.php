<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Transcode\Application\Port\TranscodeLoopLeaseInterface;
use Closure;
use Throwable;

/** Local lease deadline and observed ownership; does not fence database writes. */
final class TranscodeLoopOwnership
{
    private bool $lost = false;
    private bool $closed = false;

    private ?int $deadline = null;
    private bool $renewing = false;
    /** @var Closure(): int */
    private readonly Closure $monotonicClock;

    /** @param (Closure(): int)|null $monotonicClock */
    public function __construct(public readonly TranscodeLoopLeaseInterface $lease, ?Closure $monotonicClock = null)
    {
        $this->monotonicClock = $monotonicClock ?? static fn(): int => hrtime(true);
    }

    public function renew(int $ttlSeconds): bool
    {
        $this->observeExpiry();
        if ($this->lost || $this->closed) {
            return false;
        }
        // Timer callbacks may overlap while Redis I/O yields. Only one renewal
        // may establish a deadline; another callback can only use existing time.
        if ($this->renewing) {
            return $this->isActive();
        }
        $this->renewing = true;
        $ttlSeconds = max(1, $ttlSeconds);
        $started = ($this->monotonicClock)();
        $previousDeadline = $this->deadline;
        try {
            $renewed = $this->lease->renew($ttlSeconds);
        } catch (Throwable) {
            $renewed = false;
        } finally {
            $this->renewing = false;
        }
        $now = ($this->monotonicClock)();
        if ($this->lost || $this->closed) {
            return false;
        }
        // Charge all request/response time to the granted TTL. A successful
        // reply cannot restore continuity after our previous deadline elapsed.
        $deadline = $started + $ttlSeconds * 1_000_000_000;
        if (!$renewed || $now >= $deadline || ($previousDeadline !== null && $now >= $previousDeadline)) {
            $this->markLost();
            return false;
        }
        $this->deadline = $deadline;
        return true;
    }

    public function markLost(): void
    {
        $this->lost = true;
    }

    public function close(): void
    {
        $this->observeExpiry();
        $this->closed = true;
    }

    public function isActive(): bool
    {
        $this->observeExpiry();
        return $this->deadline !== null && !$this->lost && !$this->closed;
    }

    public function isLost(): bool
    {
        $this->observeExpiry();
        return $this->lost;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function observeExpiry(): void
    {
        if ($this->deadline !== null && ($this->monotonicClock)() >= $this->deadline) {
            $this->markLost();
        }
    }

    public function assertOwned(): void
    {
        if (!$this->isActive()) {
            throw new TranscodeLoopOwnershipLost();
        }
    }
}
