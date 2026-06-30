<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'party_events')]
class PartyEventEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sessionId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'text')]
    private string $action;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $position;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        Uuid $sessionId,
        Uuid $userId,
        string $action,
        ?float $position = null,
        ?\DateTimeImmutable $occurredAt = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->action = $action;
        $this->position = $position;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getSessionId(): Uuid { return $this->sessionId; }
    public function getUserId(): Uuid { return $this->userId; }
    public function getAction(): string { return $this->action; }
    public function getPosition(): ?float { return $this->position; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
}
