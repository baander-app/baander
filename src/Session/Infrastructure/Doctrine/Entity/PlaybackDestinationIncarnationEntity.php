<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

// Rows change only through the playback store's guarded statements, never through a managed-entity flush.
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'playback_destination_incarnations')]
#[ORM\UniqueConstraint(name: 'uniq_playback_destination_incarnations_id_user_id', columns: ['id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_playback_destination_incarnations_destination_id_epoch', columns: ['destination_id', 'epoch'])]
#[ORM\Index(name: 'idx_playback_destination_incarnations_destination_id_user_id', columns: ['destination_id', 'user_id'])]
final class PlaybackDestinationIncarnationEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $destinationId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'integer')]
    private int $epoch;

    #[ORM\Column(type: 'text')]
    private string $capabilityHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $observedVolume = null;

    #[ORM\Column(type: 'text', options: ['default' => 'unknown'])]
    private string $observedState = 'unknown';

    #[ORM\Column(type: 'float', options: ['default' => '0'])]
    private float $position = 0.0;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $tabId;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $localRecoveryEligible;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $recoveryCredentialHash;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $recoveryExpiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $recoveryRevokedAt = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $recoveryResult = null;

    /**
     * An incarnation eligible for local recovery carries its tab, credential hash and expiry;
     * any other incarnation carries none of them. The table's check constraint enforces both.
     */
    public function __construct(
        Uuid $id,
        Uuid $destinationId,
        Uuid $userId,
        int $epoch,
        string $capabilityHash,
        \DateTimeImmutable $expiresAt,
        bool $localRecoveryEligible = false,
        ?Uuid $tabId = null,
        ?string $recoveryCredentialHash = null,
        ?\DateTimeImmutable $recoveryExpiresAt = null,
    ) {
        $this->id = $id;
        $this->destinationId = $destinationId;
        $this->userId = $userId;
        $this->epoch = $epoch;
        $this->capabilityHash = $capabilityHash;
        $this->expiresAt = $expiresAt;
        $this->localRecoveryEligible = $localRecoveryEligible;
        $this->tabId = $tabId;
        $this->recoveryCredentialHash = $recoveryCredentialHash;
        $this->recoveryExpiresAt = $recoveryExpiresAt;
    }
}
