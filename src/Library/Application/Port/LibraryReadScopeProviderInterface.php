<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\ValueObject\LibraryReadScope;

interface LibraryReadScopeProviderInterface
{
    public function current(): LibraryReadScope;
}
