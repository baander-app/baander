<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/** Identity projection for public socket messages and internal dispatch. */
interface WebSocketIdentityLookupInterface
{
    public function partyId(PublicId $publicId): ?Uuid;

    public function userPublicId(Uuid $userId): ?PublicId;

    public function transcodeSessionId(PublicId $publicId, Uuid $ownerId): ?Uuid;
}
