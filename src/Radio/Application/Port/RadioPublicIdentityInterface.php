<?php

declare(strict_types=1);

namespace App\Radio\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface RadioPublicIdentityInterface
{
    public function userPublicId(Uuid $userId): PublicId;
}
