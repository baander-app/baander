<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Media\Domain\Model\TrackStreamMetadata;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface StreamPortInterface
{
    /**
     * The real path of the track's file inside its library.
     *
     * @throws ConflictException with reason `media_unavailable` when the path, or a symlink on it, leads outside the library
     * @throws \InvalidArgumentException when the track, its library or its file is missing
     */
    public function resolveTrackPath(PublicId $trackId): string;

    public function getTrackMetadata(PublicId $trackId): TrackStreamMetadata;

    /**
     * Resolve the library UUID that owns the track, if the track exists.
     */
    public function getLibraryIdForTrack(PublicId $trackId): ?Uuid;
}
