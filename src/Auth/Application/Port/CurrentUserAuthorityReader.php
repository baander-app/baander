<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Auth\Application\DTO\CurrentUserAuthority;
use App\Shared\Domain\Model\Uuid;

interface CurrentUserAuthorityReader
{
    public function read(Uuid $principal): ?CurrentUserAuthority;
}
