<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'genre_album')]
#[ORM\UniqueConstraint(name: 'genre_album_unique', columns: ['genre_id', 'album_id'])]
#[ORM\Index(name: 'idx_genre_album_genre_id', columns: ['genre_id'])]
#[ORM\Index(name: 'idx_genre_album_album_id', columns: ['album_id'])]
class GenreAlbumEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GenreEntity::class)]
    #[ORM\JoinColumn(name: 'genre_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private GenreEntity $genre;

    #[ORM\ManyToOne(targetEntity: AlbumEntity::class)]
    #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AlbumEntity $album;

    public function __construct(
        GenreEntity $genre,
        AlbumEntity $album,
    ) {
        $this->id = new Uuid();
        $this->genre = $genre;
        $this->album = $album;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGenre(): GenreEntity
    {
        return $this->genre;
    }

    public function getAlbum(): AlbumEntity
    {
        return $this->album;
    }
}
