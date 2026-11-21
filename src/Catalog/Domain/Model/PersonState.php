<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final class PersonState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public string $name,
    ) {}
}
