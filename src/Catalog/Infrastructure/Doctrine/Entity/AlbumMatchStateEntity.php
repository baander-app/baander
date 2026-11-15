<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

/**
 * An album's place in automatic MusicBrainz matching: a match it waits for, or the review an admin
 * owes it. It lives beside the album rather than in it, so that its live updates reach admins only.
 * The row is deleted with its album (fk_album_match_states_album_id).
 *
 * Read-only for the unit of work: AlbumMatchStateRepository writes every row with guarded single
 * statements, and the mapping only declares the table to the schema tooling.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'album_match_states')]
#[ORM\Index(name: 'idx_album_match_states_state_due_at', columns: ['state', 'due_at'])]
class AlbumMatchStateEntity
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: AlbumEntity::class)]
    #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AlbumEntity $album;

    #[ORM\Column(type: 'text')]
    private string $state;

    #[ORM\Column(type: 'text')]
    private string $source;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dueAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $checkedSongCount = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $bestScore = null;

    /** The claim of the queue run that matches the album; only a request holds one. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $claimId = null;

    /** When the claim lapses, on the database clock; another run may take the request from then on. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimExpiresAt = null;

    /** The earliest due time a request asked for while the claim held the album. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextDueAt = null;

    public function __construct(AlbumEntity $album, string $state, string $source, \DateTimeImmutable $dueAt)
    {
        $this->album = $album;
        $this->state = $state;
        $this->source = $source;
        $this->dueAt = $dueAt;
    }

    public function getAlbum(): AlbumEntity
    {
        return $this->album;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCheckedSongCount(): ?int
    {
        return $this->checkedSongCount;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function getBestScore(): ?int
    {
        return $this->bestScore;
    }

    public function getClaimId(): ?Uuid
    {
        return $this->claimId;
    }

    public function getClaimExpiresAt(): ?\DateTimeImmutable
    {
        return $this->claimExpiresAt;
    }

    public function getNextDueAt(): ?\DateTimeImmutable
    {
        return $this->nextDueAt;
    }
}
