<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'resume_positions')]
#[ORM\UniqueConstraint(name: 'uniq_resume_positions_user_id_video_id', columns: ['user_id', 'video_id'])]
#[ORM\Index(name: 'idx_resume_positions_video_id', columns: ['video_id'])]
class ResumePositionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $videoId;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $positionSeconds;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $reportedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastPlayedAt;
}
