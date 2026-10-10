<?php

declare(strict_types=1);

namespace App\Library\Application\Service;

use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Renews a library claim at most once per renewal interval, for the scan or delete with files
 * that holds it, so frequent calls cost nothing. Without a last renewal, the next call renews.
 */
final class LibraryClaimRenewal
{
    public function __construct(
        private readonly LibraryRepositoryInterface $libraries,
        private readonly ClockInterface $clock,
        private readonly Uuid $claimId,
        private readonly int $leaseSeconds,
        private readonly int $renewalIntervalSeconds,
        private ?DateTimeImmutable $renewedAt,
    ) {
    }

    /**
     * Extends the lease when a renewal is due.
     *
     * @return bool false when a due renewal found the claim released or taken over by another holder
     */
    public function renew(): bool
    {
        $now = $this->clock->now();
        if ($this->renewedAt instanceof DateTimeImmutable) {
            $elapsed = $now->getTimestamp() - $this->renewedAt->getTimestamp();
            // A wall clock set back counts as due, so a clock change cannot starve the lease.
            if ($elapsed >= 0 && $elapsed < $this->renewalIntervalSeconds) {
                return true;
            }
        }

        if (!$this->libraries->renewClaim($this->claimId, $this->leaseSeconds)) {
            return false;
        }
        $this->renewedAt = $now;

        return true;
    }
}
