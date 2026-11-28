<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'video_match_states')]
#[ORM\UniqueConstraint(name: 'uniq_video_match_states_series_id', columns: ['series_id'])]
#[ORM\UniqueConstraint(name: 'uniq_video_match_states_movie_id', columns: ['movie_id'])]
#[ORM\Index(name: 'idx_video_match_states_status_due_at_id', columns: ['status', 'due_at', 'id'])]
class VideoMatchStateEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $seriesId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $movieId = null;

    #[ORM\Column(type: 'text')]
    private string $status;

    #[ORM\Column(type: 'text')]
    private string $source;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $dueAt;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $claimId = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $claimExpiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $nextDueAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $bestScore = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $checkedAt = null;
}
