<?php

declare(strict_types=1);

namespace App\Playlist\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface PlaylistAudienceReader
{
    /** @return list<string> */
    public function readablePlaylistIds(Uuid $principal, bool $administrator): array;
}
