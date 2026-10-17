<?php

declare(strict_types=1);

namespace App\Activity\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface ActivityIdentityProjectionPortInterface
{
    public function readScope(Uuid $userId, bool $admin): LibraryReadScope;

    public function userPublicId(Uuid $userId): PublicId;

    public function artistPublicId(Uuid $artistId): ?PublicId;
}
