<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

// Rows change only through the playback store's guarded statements, never through a managed-entity flush.
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'playback_transfers')]
#[ORM\UniqueConstraint(name: 'uniq_playback_transfers_session_id_request_id', columns: ['session_id', 'request_id'])]
#[ORM\UniqueConstraint(name: 'uniq_playback_transfers_id_user_id', columns: ['id', 'user_id'])]
// At most one transfer per session is open; the predicate is written as PostgreSQL reports it.
#[ORM\UniqueConstraint(name: 'uniq_playback_transfers_current', columns: ['session_id'], options: ['where' => "(state <> ALL (ARRAY['committed'::text, 'failed'::text]))"])]
#[ORM\Index(name: 'idx_playback_transfers_session_id_user_id', columns: ['session_id', 'user_id'])]
#[ORM\Index(name: 'idx_playback_transfers_source_incarnation_id_user_id', columns: ['source_incarnation_id', 'user_id'])]
#[ORM\Index(name: 'idx_playback_transfers_target_incarnation_id_user_id', columns: ['target_incarnation_id', 'user_id'])]
final class PlaybackTransferEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sessionId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $requestId;

    #[ORM\Column(type: 'text')]
    private string $requestDigest;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $sourceIncarnationId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetIncarnationId;

    #[ORM\Column(type: 'text')]
    private string $state;

    #[ORM\Column(type: 'text')]
    private string $desiredState;

    #[ORM\Column(type: 'float')]
    private float $position;

    #[ORM\Column(type: 'integer')]
    private int $revision;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        Uuid $sessionId,
        Uuid $userId,
        Uuid $requestId,
        string $requestDigest,
        ?Uuid $sourceIncarnationId,
        Uuid $targetIncarnationId,
        string $state,
        string $desiredState,
        float $position,
        int $revision,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->requestId = $requestId;
        $this->requestDigest = $requestDigest;
        $this->sourceIncarnationId = $sourceIncarnationId;
        $this->targetIncarnationId = $targetIncarnationId;
        $this->state = $state;
        $this->desiredState = $desiredState;
        $this->position = $position;
        $this->revision = $revision;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
    }
}
