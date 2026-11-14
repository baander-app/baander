<?php

declare(strict_types=1);

namespace App\Metadata\Domain\Model;

final class ExtractedMetadata
{
    /** @var list<CoverArt> */
    private array $pictures = [];
    /** @var list<string> */
    private array $artists = [];
    /** @var list<string> */
    private array $albumArtists = [];
    /** @var list<string> */
    private array $mbArtistIds = [];
    /** @var list<string> */
    private array $mbAlbumArtistIds = [];

    public function __construct(
        private ?string $title = null,
        private ?string $album = null,
        ?string $artist = null,
        ?string $albumArtist = null,
        private ?int $trackNumber = null,
        private ?int $discNumber = null,
        private ?int $year = null,
        /** @var string[] */
        private array $genre = [],
        private ?string $comment = null,
        private ?string $composer = null,
        private ?int $bpm = null,
        private ?float $duration = null,
        private ?int $bitrate = null,
        private ?int $sampleRate = null,
        private ?int $channels = null,
        private ?string $mbid = null,
        private ?string $mbAlbumId = null,
        ?string $mbArtistId = null,
        /** @var string[] */
        private array $coverArt = [],
    ) {
        $this->setArtist($artist);
        $this->setAlbumArtist($albumArtist);
        $this->setMbArtistId($mbArtistId);

        if (empty($this->genre)) {
            $this->genre = [];
        }

        if (empty($this->coverArt)) {
            $this->coverArt = [];
        }
    }

    // Builder-style setters
    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function setAlbum(?string $album): self
    {
        $this->album = $album;
        return $this;
    }

    public function setArtist(?string $artist): self
    {
        return $this->setArtists(self::single($artist));
    }

    /** @param list<string> $artists every artist value, in tag order */
    public function setArtists(array $artists): self
    {
        $this->artists = $artists;
        return $this;
    }

    public function setAlbumArtist(?string $albumArtist): self
    {
        return $this->setAlbumArtists(self::single($albumArtist));
    }

    /** @param list<string> $albumArtists every album-artist value, in tag order */
    public function setAlbumArtists(array $albumArtists): self
    {
        $this->albumArtists = $albumArtists;
        return $this;
    }

    public function setTrackNumber(?int $trackNumber): self
    {
        $this->trackNumber = $trackNumber;
        return $this;
    }

    public function setDiscNumber(?int $discNumber): self
    {
        $this->discNumber = $discNumber;
        return $this;
    }

    public function setYear(?int $year): self
    {
        $this->year = $year;
        return $this;
    }

    /** @param string[] $genre */
    public function setGenre(array $genre): self
    {
        $this->genre = $genre;
        return $this;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    public function setComposer(?string $composer): self
    {
        $this->composer = $composer;
        return $this;
    }

    public function setBpm(?int $bpm): self
    {
        $this->bpm = $bpm;
        return $this;
    }

    public function setDuration(?float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    public function setBitrate(?int $bitrate): self
    {
        $this->bitrate = $bitrate;
        return $this;
    }

    public function setSampleRate(?int $sampleRate): self
    {
        $this->sampleRate = $sampleRate;
        return $this;
    }

    public function setChannels(?int $channels): self
    {
        $this->channels = $channels;
        return $this;
    }

    public function setMbid(?string $mbid): self
    {
        $this->mbid = $mbid;
        return $this;
    }

    public function setMbAlbumId(?string $mbAlbumId): self
    {
        $this->mbAlbumId = $mbAlbumId;
        return $this;
    }

    public function setMbArtistId(?string $mbArtistId): self
    {
        return $this->setMbArtistIds(self::single($mbArtistId));
    }

    /** @param list<string> $mbArtistIds every MusicBrainz artist ID, in tag order */
    public function setMbArtistIds(array $mbArtistIds): self
    {
        $this->mbArtistIds = $mbArtistIds;
        return $this;
    }

    /** @param list<string> $mbAlbumArtistIds every MusicBrainz album-artist ID, in tag order */
    public function setMbAlbumArtistIds(array $mbAlbumArtistIds): self
    {
        $this->mbAlbumArtistIds = $mbAlbumArtistIds;
        return $this;
    }

    /** @param string[] $coverArt */
    public function setCoverArt(array $coverArt): self
    {
        $this->coverArt = $coverArt;
        return $this;
    }

    // Getters
    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getAlbum(): ?string
    {
        return $this->album;
    }

    /** The first artist value. */
    public function getArtist(): ?string
    {
        return $this->artists[0] ?? null;
    }

    /** @return list<string> */
    public function getArtists(): array
    {
        return $this->artists;
    }

    /** The first album-artist value. */
    public function getAlbumArtist(): ?string
    {
        return $this->albumArtists[0] ?? null;
    }

    /** @return list<string> */
    public function getAlbumArtists(): array
    {
        return $this->albumArtists;
    }

    public function getTrackNumber(): ?int
    {
        return $this->trackNumber;
    }

    public function getDiscNumber(): ?int
    {
        return $this->discNumber;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    /** @return string[] */
    public function getGenre(): array
    {
        return $this->genre;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getComposer(): ?string
    {
        return $this->composer;
    }

    public function getBpm(): ?int
    {
        return $this->bpm;
    }

    public function getDuration(): ?float
    {
        return $this->duration;
    }

    public function getBitrate(): ?int
    {
        return $this->bitrate;
    }

    public function getSampleRate(): ?int
    {
        return $this->sampleRate;
    }

    public function getChannels(): ?int
    {
        return $this->channels;
    }

    public function getMbid(): ?string
    {
        return $this->mbid;
    }

    public function getMbAlbumId(): ?string
    {
        return $this->mbAlbumId;
    }

    /** The MusicBrainz artist ID when the file carries exactly one, else null. */
    public function getMbArtistId(): ?string
    {
        return count($this->mbArtistIds) === 1 ? $this->mbArtistIds[0] : null;
    }

    /** @return list<string> */
    public function getMbArtistIds(): array
    {
        return $this->mbArtistIds;
    }

    /** @return list<string> */
    public function getMbAlbumArtistIds(): array
    {
        return $this->mbAlbumArtistIds;
    }

    /** @return string[] */
    public function getCoverArt(): array
    {
        return $this->coverArt;
    }

    /** @param list<CoverArt> $pictures */
    public function setPictures(array $pictures): self
    {
        $this->pictures = $pictures;
        return $this;
    }

    /** @return list<CoverArt> */
    public function getPictures(): array
    {
        return $this->pictures;
    }

    public function getFrontCover(): ?CoverArt
    {
        $front = array_filter($this->pictures, fn (CoverArt $p) => $p->isCoverFront());

        return $front !== [] ? reset($front) : ($this->pictures[0] ?? null);
    }

    /** @return list<string> */
    private static function single(?string $value): array
    {
        return $value === null ? [] : [$value];
    }

    // Static factory method
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $metadata = new self();

        // Tag mappings to properties
        $tagMappings = [
            'title' => ['title', 'Title', 'TITLE', 'TIT2'],
            'album' => ['album', 'Album', 'ALBUM', 'TALB'],
            'artist' => ['artist', 'Artist', 'ARTIST', 'TPE1'],
            'albumArtist' => ['albumartist', 'AlbumArtist', 'ALBUMARTIST', 'TPE2'],
            'trackNumber' => ['tracknumber', 'TrackNumber', 'TRACKNUMBER', 'TRCK'],
            'discNumber' => ['discnumber', 'DiscNumber', 'DISCNUMBER'],
            'year' => ['year', 'Year', 'YEAR', 'TDRC'],
            'genre' => ['genre', 'Genre', 'GENRE', 'TCON'],
            'comment' => ['comment', 'Comment', 'COMMENT', 'COMM'],
            'composer' => ['composer', 'Composer', 'COMPOSER', 'TCOM'],
            'bpm' => ['bpm', 'BPM', 'TBPM'],
            'duration' => ['duration', 'Duration', 'DURATION'],
            'bitrate' => ['bitrate', 'Bitrate', 'BITRATE'],
            'sampleRate' => ['samplerate', 'SampleRate', 'SAMPLERATE'],
            'channels' => ['channels', 'Channels', 'CHANNELS'],
            'mbid' => ['mbid', 'MBID'],
            'mbAlbumId' => ['mbalbumid', 'MbAlbumId', 'MBALBUMID'],
            'mbArtistId' => ['mbartistid', 'MbArtistId', 'MBARTISTID'],
            'coverArt' => ['coverart', 'CoverArt', 'COVERART'],
        ];

        foreach ($tagMappings as $property => $tags) {
            if (!isset($data[$property])) {
                continue;
            }

            $value = $data[$property];

            // Handle arrays for genre and coverArt
            if (in_array($property, ['genre', 'coverArt'], true)) {
                if (is_string($value)) {
                    // Convert comma-separated string to array
                    $value = array_filter(array_map('trim', explode(',', $value)));
                } elseif (!is_array($value)) {
                    continue;
                }
            } else {
                // Ensure null for empty values
                if (is_string($value) && trim($value) === '') {
                    $value = null;
                }
            }

            // Set the property using the setter
            $setter = 'set' . ucfirst($property);
            if (method_exists($metadata, $setter)) {
                $metadata->$setter($value);
            }
        }

        return $metadata;
    }
}