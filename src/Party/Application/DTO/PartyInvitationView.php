<?php

declare(strict_types=1);

namespace App\Party\Application\DTO;

use App\Party\Domain\Model\PartyInvitation;
use App\Shared\Domain\Model\PublicId;

final readonly class PartyInvitationView
{
    public function __construct(
        public PartyInvitation $model,
        public PublicId $userId,
    ) {
    }
}
