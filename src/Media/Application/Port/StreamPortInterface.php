<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Media\Domain\Model\TrackStreamMetadata;
use App\Shared\Domain\Model\PublicId;

interface StreamPortInterface
{
    public function resolveTrackPath(PublicId $trackId): string;

    public function getTrackMetadata(PublicId $trackId): TrackStreamMetadata;
}
