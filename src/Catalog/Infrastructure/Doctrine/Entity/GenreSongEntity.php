<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'genre_song')]
#[ORM\UniqueConstraint(name: 'genre_song_unique', columns: ['genre_id', 'song_id'])]
#[ORM\Index(name: 'idx_genre_song_song_id', columns: ['song_id'])]
class GenreSongEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GenreEntity::class)]
    #[ORM\JoinColumn(name: 'genre_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private GenreEntity $genre;

    #[ORM\ManyToOne(targetEntity: SongEntity::class, inversedBy: 'genres')]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SongEntity $song;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $position = null;

    public function __construct(
        GenreEntity $genre,
        SongEntity $song,
        ?int $position = null,
    ) {
        $this->id = new Uuid();
        $this->genre = $genre;
        $this->song = $song;
        $this->position = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGenre(): GenreEntity
    {
        return $this->genre;
    }

    public function getSong(): SongEntity
    {
        return $this->song;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): void
    {
        $this->position = $position;
    }
}
