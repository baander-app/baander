<?php

declare(strict_types=1);

namespace App\Media\Application\Port;

use App\Shared\Domain\ValueObject\MediaReadScope;

interface MediaReadScopeProviderInterface
{
    public function current(): MediaReadScope;
}
