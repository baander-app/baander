<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface LibraryReadScopeProviderInterface
{
    public function forUser(Uuid $userId): LibraryReadScope;

    public function current(): LibraryReadScope;
}
