<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Media\Domain\Model\TrackStreamMetadata;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface StreamPortInterface
{
    public function resolveTrackPath(PublicId $trackId): string;

    public function getTrackMetadata(PublicId $trackId): TrackStreamMetadata;

    /**
     * Resolve the library UUID that owns the track, if the track exists.
     */
    public function getLibraryIdForTrack(PublicId $trackId): ?Uuid;
}
