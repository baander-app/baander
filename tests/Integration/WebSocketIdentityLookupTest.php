<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\ValueObject\MemberRole;
use App\Party\Infrastructure\Doctrine\Repository\PartyInvitationRepository;
use App\Party\Infrastructure\Doctrine\Repository\PartyMemberRepository;
use App\Party\Infrastructure\Doctrine\Repository\SyncedPartySessionRepository;
use App\Party\Infrastructure\PartyInvitationService;
use App\Party\Infrastructure\PartyMemberService;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Swoole\WebSocketIdentityLookup;
use PHPUnit\Framework\TestCase;

/**
 * A socket message that names a party finds it only through the sender's member row, so a user
 * without one is answered as for a public ID that no party has.
 */
final class WebSocketIdentityLookupTest extends TestCase
{
    use OwnershipPersistenceHarness;

    public function testAPartyIsFoundByItsPublicIdOnlyForAUserWhoHoldsAMemberRowInIt(): void
    {
        $host = $this->createUser();
        $member = $this->createUser();
        $invited = $this->createUser();
        $revoked = $this->createUser();
        $uninvited = $this->createUser();
        $party = $this->party($host);
        // The uninvited user is a member elsewhere: of a party of their own.
        $elsewhere = $this->party($uninvited);
        $members = new PartyMemberService(new PartyMemberRepository($this->manager));
        $invitations = new PartyInvitationService(new PartyInvitationRepository($this->manager));
        foreach ([$member, $invited, $revoked] as $user) {
            $invitations->invite($user, $party->getId());
        }
        $members->addMember($member, $party->getId());
        $members->addMember($revoked, $party->getId());
        // A revoke deletes the user's invitation and member row.
        self::assertTrue($invitations->revoke($revoked, $party->getId()));
        self::assertTrue($members->removeMember($revoked, $party->getId()));
        $this->manager->clear();

        $lookup = new WebSocketIdentityLookup($this->manager->getConnection());

        self::assertTrue($lookup->partyIdForMember($party->getPublicId(), $host)?->equals($party->getId()), 'The host holds a member row.');
        self::assertTrue($lookup->partyIdForMember($party->getPublicId(), $member)?->equals($party->getId()), 'A user who joined holds a member row.');
        self::assertTrue($lookup->partyIdForMember($elsewhere->getPublicId(), $uninvited)?->equals($elsewhere->getId()));
        self::assertNull($lookup->partyIdForMember($party->getPublicId(), $invited), 'An invitation alone is no member row.');
        self::assertNull($lookup->partyIdForMember($party->getPublicId(), $uninvited));
        self::assertNull($lookup->partyIdForMember($party->getPublicId(), $revoked));
        self::assertNull($lookup->partyIdForMember(new PublicId(), $host), 'No party has the public ID.');
    }

    /** A stored party whose host holds the host's member row, as a created party does. */
    private function party(Uuid $host): SyncedPartySession
    {
        $party = SyncedPartySession::create($host, $this->createVideo(), null);
        (new SyncedPartySessionRepository($this->manager))->save($party);
        (new PartyMemberRepository($this->manager))->save(PartyMember::create($host, $party->getId(), MemberRole::Host));

        return $party;
    }
}
