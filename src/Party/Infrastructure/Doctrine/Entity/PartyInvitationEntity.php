<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

// A row is inserted and deleted, never updated, so the unit of work computes no changes for it.
// That also keeps a loaded invitation out of every later flush. Without it, the flush after a
// party's removal refuses the removed session as a new entity found through a loaded invitation,
// whose row the database has already deleted with the party.
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'party_invitations')]
#[ORM\UniqueConstraint(name: 'uniq_party_invitations_user_id_session_id', columns: ['user_id', 'session_id'])]
#[ORM\Index(name: 'idx_party_invitations_session_id', columns: ['session_id'])]
class PartyInvitationEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(name: 'user_id', type: 'uuid')]
    private Uuid $userId;

    #[ORM\ManyToOne(targetEntity: SyncedPartySessionEntity::class)]
    #[ORM\JoinColumn(name: 'session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SyncedPartySessionEntity $session;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        Uuid $userId,
        SyncedPartySessionEntity $session,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->session = $session;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid { return $this->id; }
    public function getUserId(): Uuid { return $this->userId; }
    public function getSessionId(): Uuid { return $this->session->getId(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
