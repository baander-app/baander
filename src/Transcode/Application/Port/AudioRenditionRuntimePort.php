<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\DTO\AudioRenditionClaim;
use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\DTO\AudioRenditionRuntimeSnapshot;
use App\Transcode\Application\DTO\MediaWorkerIdentity;
use App\Transcode\Domain\ValueObject\AacImplementation;

interface AudioRenditionRuntimePort
{
    public function admit(AudioRenditionInput $input): AudioRenditionRuntimeSnapshot;
    public function find(string $key): ?AudioRenditionRuntimeSnapshot;
    /**
     * Includes pending and expired running work, independently of notifications.
     * @return list<AudioRenditionRuntimeSnapshot>
     */
    public function pending(int $limit = 100): array;
    public function claim(string $key, MediaWorkerIdentity $worker, int $leaseSeconds = 15): ?AudioRenditionClaim;
    public function renew(AudioRenditionClaim $claim, int $leaseSeconds = 15): bool;
    /** Called only after acquiring the canonical filesystem lock nonblocking, outside a transaction. */
    public function start(AudioRenditionClaim $claim, string $partialPath, string $finalPath): bool;
    /**
     * Hold the live claim while atomically renaming its generation-local output and completing publication.
     * Encoding and waits must finish before entering the callback.
     * @param callable(): void $operation
     */
    public function withOwnership(AudioRenditionClaim $claim, callable $operation): bool;
    public function complete(AudioRenditionClaim $claim): bool;
    /** Reopen the observed completed generation after the caller verifies its accepted file is absent. */
    public function resetCompleted(string $key, int $generation): bool;
    /** Record the failure, and the AAC implementation the failed encode used when it used one. */
    public function fail(AudioRenditionClaim $claim, string $error, ?AacImplementation $aacImplementation = null): bool;
    /**
     * Reopen the observed failed generation when it failed under an AAC implementation other than
     * the current one, so switching the implementation setting retries it. A failure that recorded
     * no implementation stays failed.
     */
    public function resetFailed(string $key, int $generation, AacImplementation $current): bool;
    public function defer(AudioRenditionClaim $claim): bool;
}
