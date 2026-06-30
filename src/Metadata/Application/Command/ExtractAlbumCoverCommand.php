<?php

declare(strict_types=1);

namespace App\Metadata\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class ExtractAlbumCoverCommand
{
    public function __construct(
        private Uuid $albumId,
    ) {
    }

    public function getAlbumId(): Uuid
    {
        return $this->albumId;
    }
}
