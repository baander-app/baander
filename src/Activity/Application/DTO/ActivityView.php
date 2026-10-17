<?php

declare(strict_types=1);

namespace App\Activity\Application\DTO;

use App\Activity\Domain\Model\MediaActivity;
use App\Shared\Domain\Model\PublicId;

final readonly class ActivityView
{
    public function __construct(
        public MediaActivity $model,
        public PublicId $userId,
        public ?PublicId $songId,
        public ?PublicId $albumId,
        public ?PublicId $artistId,
        public ?PublicId $movieId,
    ) {
    }
}
