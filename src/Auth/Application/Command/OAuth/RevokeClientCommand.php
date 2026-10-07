<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/** Revoke a client owned by the requesting user, together with its tokens. */
final readonly class RevokeClientCommand
{
    public function __construct(
        public Uuid $userId,
        public PublicId $clientPublicId,
    ) {
    }
}
