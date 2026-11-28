<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'watched_marks')]
#[ORM\UniqueConstraint(name: 'uniq_watched_marks_user_id_episode_id', columns: ['user_id', 'episode_id'])]
#[ORM\UniqueConstraint(name: 'uniq_watched_marks_user_id_movie_id', columns: ['user_id', 'movie_id'])]
#[ORM\Index(name: 'idx_watched_marks_episode_id', columns: ['episode_id'])]
#[ORM\Index(name: 'idx_watched_marks_movie_id', columns: ['movie_id'])]
class WatchedMarkEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $episodeId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $movieId;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $watchedAt;
}
