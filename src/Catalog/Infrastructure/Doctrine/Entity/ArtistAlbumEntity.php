<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'artist_album')]
#[ORM\UniqueConstraint(name: 'artist_album_role_unique', columns: ['artist_id', 'album_id', 'role'])]
#[ORM\Index(name: 'idx_artist_album_artist_id', columns: ['artist_id'])]
#[ORM\Index(name: 'idx_artist_album_album_id', columns: ['album_id'])]
class ArtistAlbumEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArtistEntity::class)]
    #[ORM\JoinColumn(name: 'artist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ArtistEntity $artist;

    #[ORM\ManyToOne(targetEntity: AlbumEntity::class)]
    #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AlbumEntity $album;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $role = null;

    public function __construct(
        ArtistEntity $artist,
        AlbumEntity $album,
        ?string $role = null,
    ) {
        $this->id = new Uuid();
        $this->artist = $artist;
        $this->album = $album;
        $this->role = $role;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArtist(): ArtistEntity
    {
        return $this->artist;
    }

    public function getAlbum(): AlbumEntity
    {
        return $this->album;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): void
    {
        $this->role = $role;
    }
}
