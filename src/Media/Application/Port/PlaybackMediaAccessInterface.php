<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface PlaybackMediaAccessInterface
{
    public function assertTrackAccess(Uuid $owner, PublicId $track): void;

    public function assertVideoAccess(Uuid $owner, PublicId $video): void;
}
