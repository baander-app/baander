<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/** Identity projection for public socket messages and internal dispatch. */
interface WebSocketIdentityLookupInterface
{
    public function partyId(PublicId $publicId): ?Uuid;

    /**
     * The ID of the party with the public ID when the user holds a member row in it, as stored now.
     *
     * Null both for a party the user is not a member of and for a public ID that no party has, so
     * a message that names a party answers a user without a member row as it answers for a party
     * that does not exist.
     */
    public function partyIdForMember(PublicId $publicId, Uuid $userId): ?Uuid;

    public function userPublicId(Uuid $userId): ?PublicId;

    public function transcodeSessionId(PublicId $publicId, Uuid $ownerId): ?Uuid;
}
