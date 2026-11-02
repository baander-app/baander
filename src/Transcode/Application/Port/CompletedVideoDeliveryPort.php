<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Transcode\Application\DTO\MediaTrack;

interface CompletedVideoDeliveryPort
{
    /** Retain sealed media until the signed capability expires; missing media returns false. */
    public function grant(PublicId $jobId, int $generation, int $expiresAt): bool;

    public function master(PublicId $jobId, int $generation, int $expiresAt): ?string;

    public function playlist(PublicId $jobId, int $generation, MediaTrack $track, int $expiresAt): ?string;

    /** Resolve only a sealed, granted file; null index selects the track initialization file. */
    public function path(PublicId $jobId, int $generation, MediaTrack $track, ?int $index, int $expiresAt): ?string;
}
