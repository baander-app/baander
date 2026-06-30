<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class Song
{
    /** @var string[] */
    private const LOCKABLE_FIELDS = [
        'title', 'track', 'disc', 'year', 'comment', 'lyrics', 'explicit',
    ];

    private function __construct(
        private SongState $state,
    ) {
    }

    /**
     * Create a new Song aggregate root.
     */
    public static function create(
        Uuid $album,
        string $title,
        string $path,
        int $size,
        string $mimeType,
        ?float $length = null,
        ?string $lyrics = null,
        ?int $track = null,
        ?int $disc = null,
        ?int $year = null,
        ?string $comment = null,
        ?string $hash = null,
        ?int $bitrate = null,
        ?int $sampleRate = null,
        ?int $channels = null,
        ?string $codec = null,
        bool $explicit = false,
    ): self {
        if (trim($title) === '') {
            throw new InvalidArgumentException('Song title cannot be empty.');
        }

        if (trim($path) === '') {
            throw new InvalidArgumentException('Song path cannot be empty.');
        }

        if ($size < 0) {
            throw new InvalidArgumentException('Song size must be non-negative.');
        }

        return new self(new SongState(
            id: new Uuid(),
            publicId: new PublicId(),
            album: $album,
            title: $title,
            path: $path,
            size: $size,
            mimeType: $mimeType,
            length: $length,
            lyrics: $lyrics,
            track: $track,
            disc: $disc,
            year: $year,
            comment: $comment,
            hash: $hash,
            bitrate: $bitrate,
            sampleRate: $sampleRate,
            channels: $channels,
            codec: $codec,
            explicit: $explicit,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        ));
    }

    /**
     * Reconstitute a Song from persistence.
     *
     * This is intended for use by the repository layer only.
     */
    public static function reconstitute(SongState $state): self
    {
        return new self($state);
    }

    /**
     * Update song metadata fields.
     */
    public function updateMetadata(
        ?string $title = null,
        ?int $track = null,
        ?int $disc = null,
        ?int $year = null,
        ?string $comment = null,
        ?string $lyrics = null,
        ?bool $explicit = null,
    ): void {
        if ($title !== null && $this->isFieldLocked('title')) {
            throw new InvalidArgumentException('Field "title" is locked and cannot be updated.');
        }

        if ($title !== null) {
            if (trim($title) === '') {
                throw new InvalidArgumentException('Song title cannot be empty.');
            }
            $this->state->title = trim($title);
        }

        $this->state->track = $track ?? $this->state->track;
        $this->state->disc = $disc ?? $this->state->disc;
        $this->state->year = $year ?? $this->state->year;
        $this->state->comment = $comment ?? $this->state->comment;
        $this->state->lyrics = $lyrics ?? $this->state->lyrics;

        if ($explicit !== null) {
            $this->state->explicit = $explicit;
        }

        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Update audio file metadata (bitrate, sample rate, etc.).
     */
    public function updateAudioMetadata(
        ?int $size = null,
        ?string $mimeType = null,
        ?float $length = null,
        ?string $hash = null,
        ?int $bitrate = null,
        ?int $sampleRate = null,
        ?int $channels = null,
        ?string $codec = null,
    ): void {
        if ($size !== null) {
            $this->state->size = $size;
        }

        if ($mimeType !== null) {
            $this->state->mimeType = $mimeType;
        }

        $this->state->length = $length ?? $this->state->length;
        $this->state->hash = $hash ?? $this->state->hash;
        $this->state->bitrate = $bitrate ?? $this->state->bitrate;
        $this->state->sampleRate = $sampleRate ?? $this->state->sampleRate;
        $this->state->channels = $channels ?? $this->state->channels;
        $this->state->codec = $codec ?? $this->state->codec;

        $this->state->updatedAt = new DateTimeImmutable();
    }

    /**
     * Update audio analysis features.
     */
    public function updateAudioFeatures(
        ?float $energy = null,
        ?float $danceability = null,
        ?float $valence = null,
        ?float $acousticness = null,
        ?float $instrumentalness = null,
        ?float $liveness = null,
        ?float $spechiness = null,
        ?float $loudness = null,
    ): void {
        $this->state->energy = $energy ?? $this->state->energy;
        $this->state->danceability = $danceability ?? $this->state->danceability;
        $this->state->valence = $valence ?? $this->state->valence;
        $this->state->acousticness = $acousticness ?? $this->state->acousticness;
        $this->state->instrumentalness = $instrumentalness ?? $this->state->instrumentalness;
        $this->state->liveness = $liveness ?? $this->state->liveness;
        $this->state->spechiness = $spechiness ?? $this->state->spechiness;
        $this->state->loudness = $loudness ?? $this->state->loudness;

        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function updateExternalIds(
        ?string $mbid = null,
        ?string $discogsId = null,
        ?string $spotifyId = null,
    ): void {
        $this->state->mbid = $mbid ?? $this->state->mbid;
        $this->state->discogsId = $discogsId ?? $this->state->discogsId;
        $this->state->spotifyId = $spotifyId ?? $this->state->spotifyId;

        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function lockField(string $field): void
    {
        if (!in_array($field, self::LOCKABLE_FIELDS, true)) {
            throw new InvalidArgumentException(sprintf('Cannot lock unknown field "%s".', $field));
        }

        if (!in_array($field, $this->state->lockedFields, true)) {
            $this->state->lockedFields[] = $field;
            $this->state->updatedAt = new DateTimeImmutable();
        }
    }

    public function unlockField(string $field): void
    {
        $this->state->lockedFields = array_values(array_filter(
            $this->state->lockedFields,
            static fn(string $f): bool => $f !== $field,
        ));
        $this->state->updatedAt = new DateTimeImmutable();
    }

    public function isFieldLocked(string $field): bool
    {
        return in_array($field, $this->state->lockedFields, true);
    }

    public function getId(): Uuid
    {
        return $this->state->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->state->publicId;
    }

    public function getAlbumId(): Uuid
    {
        return $this->state->album;
    }

    public function getAlbumPublicId(): ?PublicId
    {
        return $this->state->albumPublicId;
    }

    public function getTitle(): string
    {
        return $this->state->title;
    }

    public function getPath(): string
    {
        return $this->state->path;
    }

    public function getSize(): int
    {
        return $this->state->size;
    }

    public function getMimeType(): string
    {
        return $this->state->mimeType;
    }

    public function getLength(): ?float
    {
        return $this->state->length;
    }

    public function getLyrics(): ?string
    {
        return $this->state->lyrics;
    }

    public function getTrack(): ?int
    {
        return $this->state->track;
    }

    public function getDisc(): ?int
    {
        return $this->state->disc;
    }

    public function getYear(): ?int
    {
        return $this->state->year;
    }

    public function getComment(): ?string
    {
        return $this->state->comment;
    }

    public function getHash(): ?string
    {
        return $this->state->hash;
    }

    public function getBitrate(): ?int
    {
        return $this->state->bitrate;
    }

    public function getSampleRate(): ?int
    {
        return $this->state->sampleRate;
    }

    public function getChannels(): ?int
    {
        return $this->state->channels;
    }

    public function getCodec(): ?string
    {
        return $this->state->codec;
    }

    public function isExplicit(): bool
    {
        return $this->state->explicit;
    }

    public function getEnergy(): ?float
    {
        return $this->state->energy;
    }

    public function getDanceability(): ?float
    {
        return $this->state->danceability;
    }

    public function getValence(): ?float
    {
        return $this->state->valence;
    }

    public function getAcousticness(): ?float
    {
        return $this->state->acousticness;
    }

    public function getInstrumentalness(): ?float
    {
        return $this->state->instrumentalness;
    }

    public function getLiveness(): ?float
    {
        return $this->state->liveness;
    }

    public function getSpechiness(): ?float
    {
        return $this->state->spechiness;
    }

    public function getLoudness(): ?float
    {
        return $this->state->loudness;
    }

    public function getMbid(): ?string
    {
        return $this->state->mbid;
    }

    public function getDiscogsId(): ?string
    {
        return $this->state->discogsId;
    }

    public function getSpotifyId(): ?string
    {
        return $this->state->spotifyId;
    }

    /**
     * @return string[]
     */
    public function getLockedFields(): array
    {
        return $this->state->lockedFields;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->state->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->state->updatedAt;
    }

    public function getState(): SongState
    {
        return $this->state;
    }
}
