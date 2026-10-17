<?php

declare(strict_types=1);

namespace App\Session\Application\Port;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface SessionUserPublicIdentityInterface
{
    /** @throws NotFoundException When the session owner no longer exists. */
    public function publicIdByUserId(Uuid $userId): PublicId;
}
