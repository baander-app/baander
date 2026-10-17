<?php

declare(strict_types=1);

namespace App\Party\Application\DTO;

use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Domain\Model\PublicId;

final readonly class PartySessionView
{
    public function __construct(
        public SyncedPartySession $model,
        public PublicId $hostUserId,
        public PublicId $videoId,
        public ?PublicId $transcodeJobId,
    ) {
    }
}
