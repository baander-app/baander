<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'video_placement_episode')]
#[ORM\UniqueConstraint(name: 'uniq_video_placement_episode_placement_id_episode_id', columns: ['placement_id', 'episode_id'])]
#[ORM\Index(name: 'idx_video_placement_episode_episode_id', columns: ['episode_id'])]
class VideoPlacementEpisodeEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: VideoPlacementEntity::class, inversedBy: 'episodes')]
        #[ORM\JoinColumn(name: 'placement_id', nullable: false, onDelete: 'CASCADE')]
        public VideoPlacementEntity $placement,
        #[ORM\ManyToOne(targetEntity: TvEpisodeEntity::class)]
        #[ORM\JoinColumn(name: 'episode_id', nullable: false, onDelete: 'CASCADE')]
        public TvEpisodeEntity $episode,
        #[ORM\Column(type: 'integer')]
        public int $sortOrder,
    ) {
        $this->id = Uuid::v7();
    }
}
