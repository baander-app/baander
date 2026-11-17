<?php

declare(strict_types=1);

namespace App\Party\Application\Port;

use App\Party\Application\DTO\PartyInvitationView;
use App\Party\Application\DTO\PartyMemberView;
use App\Party\Application\DTO\PartySessionView;
use App\Party\Domain\Model\PartyInvitation;
use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface PartyPublicIdentityPortInterface
{
    public function session(SyncedPartySession $session): PartySessionView;

    public function member(PartyMember $member): PartyMemberView;

    /**
     * The invitations with their users' public IDs, in the given order. All users are resolved
     * in one lookup.
     *
     * @param list<PartyInvitation> $invitations
     *
     * @return list<PartyInvitationView>
     */
    public function invitations(array $invitations): array;

    public function videoId(PublicId $publicId): Uuid;

    public function transcodeJobId(PublicId $publicId): Uuid;

    public function userId(PublicId $publicId): Uuid;

    public function userPublicId(Uuid $id): PublicId;
}
