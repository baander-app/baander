<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\DTO\AudioRenditionClaim;
use App\Transcode\Application\DTO\AudioRenditionInput;
use App\Transcode\Application\DTO\AudioRenditionRuntimeSnapshot;
use App\Transcode\Application\DTO\MediaWorkerIdentity;

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
    public function fail(AudioRenditionClaim $claim, string $error): bool;
    public function defer(AudioRenditionClaim $claim): bool;
}
