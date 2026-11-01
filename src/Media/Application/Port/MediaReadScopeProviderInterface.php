<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\MediaReadScope;

interface MediaReadScopeProviderInterface
{
    public function forUser(Uuid $userId): MediaReadScope;

    public function current(): MediaReadScope;
}
