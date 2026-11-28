<?php

declare(strict_types=1);

namespace App\Activity\Application\Command;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final readonly class SetWatchedCommand
{
    public function __construct(
        public Uuid $memberId,
        public string $kind,
        public PublicId $publicId,
        public bool $watched,
    ) {}
}
