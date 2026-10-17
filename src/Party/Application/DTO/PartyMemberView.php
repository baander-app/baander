<?php

declare(strict_types=1);

namespace App\Party\Application\DTO;

use App\Party\Domain\Model\PartyMember;
use App\Shared\Domain\Model\PublicId;

final readonly class PartyMemberView
{
    public function __construct(
        public PartyMember $model,
        public PublicId $userId,
        public PublicId $sessionId,
    ) {
    }
}
