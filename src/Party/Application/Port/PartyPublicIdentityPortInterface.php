<?php

declare(strict_types=1);

namespace App\Party\Application\Port;

use App\Party\Application\DTO\PartyMemberView;
use App\Party\Application\DTO\PartySessionView;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface PartyPublicIdentityPortInterface
{
    public function session(SyncedPartySession $session): PartySessionView;

    public function member(PartyMember $member): PartyMemberView;

    public function videoId(PublicId $publicId): Uuid;

    public function transcodeJobId(PublicId $publicId): Uuid;

    public function userId(PublicId $publicId): Uuid;

    public function userPublicId(Uuid $id): PublicId;
}
