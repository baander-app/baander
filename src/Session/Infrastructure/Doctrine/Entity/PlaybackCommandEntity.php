<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

// Rows change only through the playback store's guarded statements, never through a managed-entity flush.
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'playback_commands')]
#[ORM\UniqueConstraint(name: 'uniq_playback_commands_request_replay', columns: ['session_id', 'request_id', 'type', 'incarnation_id', 'revision'])]
// The partial predicates are written as PostgreSQL reports them.
#[ORM\Index(name: 'idx_playback_commands_replay', columns: ['incarnation_id', 'created_at'], options: ['where' => '(acknowledged_at IS NULL)'])]
#[ORM\Index(name: 'idx_playback_commands_pending_controls', columns: ['session_id', 'created_at'], options: ['where' => "((acknowledged_at IS NULL) AND (type = ANY (ARRAY['play'::text, 'pause'::text, 'seek'::text, 'load'::text, 'stop'::text, 'volume'::text, 'queue'::text])))"])]
#[ORM\Index(name: 'idx_playback_commands_session_id_user_id', columns: ['session_id', 'user_id'])]
#[ORM\Index(name: 'idx_playback_commands_incarnation_id_user_id', columns: ['incarnation_id', 'user_id'])]
final class PlaybackCommandEntity
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
    private Uuid $attemptId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $requestId;

    #[ORM\Column(type: 'text')]
    private string $requestDigest;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $incarnationId;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $epoch;

    #[ORM\Column(type: 'integer')]
    private int $revision;

    #[ORM\Column(type: 'text')]
    private string $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $payload;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $outcome;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $disclosedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $settledRevision = null;

    /**
     * A command addressed to an incarnation carries that incarnation and its epoch and starts
     * unanswered. A retained command carries neither and is stored already acknowledged with
     * the outcome `retained`. The table's check constraint enforces both forms.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(
        Uuid $id,
        Uuid $sessionId,
        Uuid $userId,
        Uuid $attemptId,
        Uuid $requestId,
        string $requestDigest,
        ?Uuid $incarnationId,
        ?int $epoch,
        int $revision,
        string $type,
        array $payload,
        ?string $outcome,
        ?\DateTimeImmutable $acknowledgedAt,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->attemptId = $attemptId;
        $this->requestId = $requestId;
        $this->requestDigest = $requestDigest;
        $this->incarnationId = $incarnationId;
        $this->epoch = $epoch;
        $this->revision = $revision;
        $this->type = $type;
        $this->payload = $payload;
        $this->outcome = $outcome;
        $this->acknowledgedAt = $acknowledgedAt;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
    }
}
