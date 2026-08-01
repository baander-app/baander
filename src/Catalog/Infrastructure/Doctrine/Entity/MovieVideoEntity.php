<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'movie_video')]
class MovieVideoEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MovieEntity::class)]
    #[ORM\JoinColumn(name: 'movie_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MovieEntity $movie;

    #[ORM\ManyToOne(targetEntity: VideoEntity::class)]
    #[ORM\JoinColumn(name: 'video_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private VideoEntity $video;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct(
        MovieEntity $movie,
        VideoEntity $video,
        int $sortOrder = 0,
    ) {
        $this->id = new Uuid();
        $this->movie = $movie;
        $this->video = $video;
        $this->sortOrder = $sortOrder;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMovie(): MovieEntity
    {
        return $this->movie;
    }

    public function getVideo(): VideoEntity
    {
        return $this->video;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }
}
