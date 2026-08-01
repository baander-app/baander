<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'artist_song')]
#[ORM\UniqueConstraint(name: 'artist_song_role_unique', columns: ['artist_id', 'song_id', 'role'])]
#[ORM\Index(name: 'idx_artist_song_artist_id', columns: ['artist_id'])]
#[ORM\Index(name: 'idx_artist_song_song_id', columns: ['song_id'])]
class ArtistSongEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: ArtistEntity::class)]
    #[ORM\JoinColumn(name: 'artist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ArtistEntity $artist;

    #[ORM\ManyToOne(targetEntity: SongEntity::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SongEntity $song;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $role = null;

    public function __construct(
        ArtistEntity $artist,
        SongEntity $song,
        ?string $role = null,
    ) {
        $this->id = new Uuid();
        $this->artist = $artist;
        $this->song = $song;
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

    public function getSong(): SongEntity
    {
        return $this->song;
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
